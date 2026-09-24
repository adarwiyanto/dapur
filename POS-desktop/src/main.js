'use strict';
const {app,BrowserWindow,ipcMain,dialog}=require('electron');
const path=require('path'); const crypto=require('crypto');
const db=require('./db'); const api=require('./api'); const session=require('./session'); const {SYNC_INTERVAL_MS}=require('./config');
let mainWindow; let syncBusy=false; let syncTimer;
function deviceId(){ let id=db.getMeta('device_id'); if(!id){id=crypto.randomUUID();db.setMeta('device_id',id);} return id; }
function currentSession(){return session.load();}
async function doSync(){
  if(syncBusy)return {ok:false,error:'Sinkronisasi sedang berjalan'}; syncBusy=true;
  try{
    const s=currentSession(); if(!s?.token) return {ok:false,error:'Belum login online'};
    let pushed=0,failed=0;
    for(const sale of db.pendingSales()){
      try{ const r=await api.pushSale(s.token,sale,deviceId()); db.markSynced(sale.uuid,r); pushed++; }
      catch(e){ db.markSyncError(sale.uuid,e.message); failed++; if(/401|token|login|otorisasi/i.test(e.message)) break; }
    }
    const master=await api.bootstrap(s.token); db.replaceMaster(master); db.setMeta('last_sync',new Date().toISOString());
    mainWindow?.webContents.send('sync-status',{online:true,last_sync:db.getMeta('last_sync'),pushed,failed});
    return {ok:true,pushed,failed,last_sync:db.getMeta('last_sync')};
  }catch(e){ mainWindow?.webContents.send('sync-status',{online:false,error:e.message,last_sync:db.getMeta('last_sync')}); return {ok:false,error:e.message,last_sync:db.getMeta('last_sync')}; }
  finally{syncBusy=false;}
}
function createWindow(){
  mainWindow=new BrowserWindow({width:1440,height:900,minWidth:1100,minHeight:700,backgroundColor:'#f5f3ee',webPreferences:{preload:path.join(__dirname,'preload.js'),contextIsolation:true,nodeIntegration:false,sandbox:false}});
  mainWindow.loadFile(path.join(__dirname,'index.html')); mainWindow.setMenuBarVisibility(false);
}
app.whenReady().then(()=>{db.initDb(app.getPath('userData'));session.init(app.getPath('userData'));createWindow();syncTimer=setInterval(doSync,SYNC_INTERVAL_MS);setTimeout(doSync,4000);});
app.on('window-all-closed',()=>{if(process.platform!=='darwin')app.quit();});
app.on('before-quit',()=>{if(syncTimer)clearInterval(syncTimer);});
ipcMain.handle('session:get',()=>{const s=currentSession();return s?{logged_in:true,user:s.user,has_token:!!s.token}:{logged_in:false};});
ipcMain.handle('auth:login',async(_e,p)=>{try{const r=await api.login(p.username,p.password,deviceId());session.save({token:r.token,user:r.user,expires_at:r.expires_at});await doSync();return {ok:true,user:r.user};}catch(e){return {ok:false,error:e.message};}});
ipcMain.handle('auth:logout',()=>{session.clear();return {ok:true};});
ipcMain.handle('products:list',(_e,q)=>db.listProducts(q||''));
ipcMain.handle('customers:list',(_e,q)=>db.listCustomers(q||''));
ipcMain.handle('sales:create',(_e,payload)=>{try{db.createSale(payload);setTimeout(doSync,100);return {ok:true};}catch(e){return {ok:false,error:e.message};}});
ipcMain.handle('sales:list',()=>db.listSales());
ipcMain.handle('sales:detail',(_e,uuid)=>db.saleDetail(uuid));
ipcMain.handle('sync:run',()=>doSync());
ipcMain.handle('sync:info',()=>({last_sync:db.getMeta('last_sync'),last_master_sync:db.getMeta('last_master_sync'),pending:db.pendingSales().length}));
ipcMain.handle('print:receipt',async(_e,uuid)=>{const sale=db.saleDetail(uuid);if(!sale)return{ok:false,error:'Transaksi tidak ditemukan'}; const win=new BrowserWindow({show:false,webPreferences:{sandbox:true}}); const html=receiptHtml(sale); await win.loadURL('data:text/html;charset=utf-8,'+encodeURIComponent(html)); return await new Promise(resolve=>win.webContents.print({silent:false,printBackground:true},(success,reason)=>{win.close();resolve(success?{ok:true}:{ok:false,error:reason});}));});
ipcMain.handle('app:about',()=>({version:app.getVersion(),device_id:deviceId(),server:'https://dapur.adena.co.id'}));
function rupiah(n){return new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(n||0));}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function receiptHtml(s){return `<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:3mm}body{font-family:Arial,sans-serif;width:72mm;margin:0 auto;font-size:11px}h2,p{text-align:center;margin:3px}hr{border:0;border-top:1px dashed #000}.row{display:flex;justify-content:space-between;gap:8px}.item{margin:6px 0}.bold{font-weight:700}</style></head><body><h2>DAPUR ADENA</h2><p>${esc(s.local_no)}</p><p>${esc(s.created_at)}</p><hr>${s.items.map(i=>`<div class="item"><div>${esc(i.item_name)}</div><div class="row"><span>${i.qty} ${esc(i.unit)} × ${rupiah(i.original_price)}</span><span>${rupiah(i.subtotal)}</span></div></div>`).join('')}<hr><div class="row"><span>Total</span><span class="bold">${rupiah(s.total_amount)}</span></div><div class="row"><span>Bayar</span><span>${rupiah(s.paid_amount)}</span></div><div class="row"><span>Kembali</span><span>${rupiah(s.change_amount)}</span></div><hr><p>Terima kasih</p></body></html>`;}

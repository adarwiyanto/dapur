'use strict';
const { DatabaseSync } = require('node:sqlite');
const path = require('path');

let db;
function initDb(userDataPath) {
  if (db) return db;
  db = new DatabaseSync(path.join(userDataPath, 'dapur-pos.sqlite'));
  db.exec('PRAGMA busy_timeout = 5000');
  db.exec('PRAGMA journal_mode = WAL');
  db.exec('PRAGMA foreign_keys = ON');
  db.exec(`
    CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE IF NOT EXISTS products(
      id INTEGER PRIMARY KEY, code TEXT, sku TEXT, name TEXT NOT NULL,
      category TEXT, unit TEXT, sale_price REAL NOT NULL DEFAULT 0,
      stock_qty REAL NOT NULL DEFAULT 0, image_path TEXT, updated_at TEXT
    );
    CREATE TABLE IF NOT EXISTS customers(
      id INTEGER PRIMARY KEY, customer_code TEXT, customer_name TEXT NOT NULL,
      phone TEXT, address TEXT, updated_at TEXT
    );
    CREATE TABLE IF NOT EXISTS transactions(
      uuid TEXT PRIMARY KEY, local_no TEXT NOT NULL, sale_date TEXT NOT NULL,
      customer_id INTEGER, customer_name TEXT, gross_amount REAL NOT NULL,
      discount_amount REAL NOT NULL, total_amount REAL NOT NULL,
      payment_method TEXT NOT NULL DEFAULT 'cash', paid_amount REAL NOT NULL DEFAULT 0,
      change_amount REAL NOT NULL DEFAULT 0, notes TEXT, status TEXT NOT NULL DEFAULT 'pending',
      server_sale_id INTEGER, server_sale_no TEXT, sync_error TEXT,
      created_at TEXT NOT NULL, synced_at TEXT
    );
    CREATE TABLE IF NOT EXISTS transaction_items(
      id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_uuid TEXT NOT NULL,
      product_id INTEGER NOT NULL, item_name TEXT NOT NULL, qty REAL NOT NULL,
      unit TEXT, original_price REAL NOT NULL, discount_type TEXT NOT NULL DEFAULT 'none',
      discount_value REAL NOT NULL DEFAULT 0, discount_amount REAL NOT NULL DEFAULT 0,
      subtotal REAL NOT NULL, FOREIGN KEY(transaction_uuid) REFERENCES transactions(uuid) ON DELETE CASCADE
    );
    CREATE INDEX IF NOT EXISTS idx_transactions_status_created ON transactions(status, created_at);
  `);
  return db;
}
function getDb(){ if(!db) throw new Error('Database belum diinisialisasi'); return db; }
function setMeta(key,value){ getDb().prepare('INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(key, String(value ?? '')); }
function getMeta(key, fallback=''){ const r=getDb().prepare('SELECT value FROM meta WHERE key=?').get(key); return r?r.value:fallback; }
function replaceMaster(data){
  const d=getDb(); d.exec('BEGIN IMMEDIATE'); try {
    d.prepare('DELETE FROM products').run();
    const ip=d.prepare('INSERT INTO products(id,code,sku,name,category,unit,sale_price,stock_qty,image_path,updated_at) VALUES(@id,@code,@sku,@name,@category,@unit,@sale_price,@stock_qty,@image_path,@updated_at)');
    for(const p of data.products||[]) ip.run({id:p.id,code:p.code||null,sku:p.sku||null,name:p.name,category:p.category||null,unit:p.unit||'pcs',sale_price:Number(p.sale_price||0),stock_qty:Number(p.stock_qty||0),image_path:p.image_path||null,updated_at:p.updated_at||null});
    d.prepare('DELETE FROM customers').run();
    const ic=d.prepare('INSERT INTO customers(id,customer_code,customer_name,phone,address,updated_at) VALUES(@id,@customer_code,@customer_name,@phone,@address,@updated_at)');
    for(const c of data.customers||[]) ic.run({id:c.id,customer_code:c.customer_code||null,customer_name:c.customer_name,phone:c.phone||null,address:c.address||null,updated_at:c.updated_at||null});
    setMeta('last_master_sync', new Date().toISOString());
    d.exec('COMMIT');
  } catch (e) { try { d.exec('ROLLBACK'); } catch {} throw e; }
}
function listProducts(search='') { const q=`%${search.trim()}%`; return getDb().prepare('SELECT * FROM products WHERE name LIKE ? OR COALESCE(sku,\'\') LIKE ? OR COALESCE(code,\'\') LIKE ? ORDER BY category,name').all(q,q,q); }
function listCustomers(search='') { const q=`%${search.trim()}%`; return getDb().prepare('SELECT * FROM customers WHERE customer_name LIKE ? OR COALESCE(phone,\'\') LIKE ? ORDER BY customer_name LIMIT 100').all(q,q); }
function createSale(sale){
  const d=getDb(); d.exec('BEGIN IMMEDIATE'); try {
    d.prepare(`INSERT INTO transactions(uuid,local_no,sale_date,customer_id,customer_name,gross_amount,discount_amount,total_amount,payment_method,paid_amount,change_amount,notes,status,created_at)
      VALUES(@uuid,@local_no,@sale_date,@customer_id,@customer_name,@gross_amount,@discount_amount,@total_amount,@payment_method,@paid_amount,@change_amount,@notes,'pending',@created_at)`).run({uuid:sale.uuid,local_no:sale.local_no,sale_date:sale.sale_date,customer_id:sale.customer_id??null,customer_name:sale.customer_name??null,gross_amount:sale.gross_amount,discount_amount:sale.discount_amount,total_amount:sale.total_amount,payment_method:sale.payment_method,paid_amount:sale.paid_amount,change_amount:sale.change_amount,notes:sale.notes??null,created_at:sale.created_at});
    const ii=d.prepare(`INSERT INTO transaction_items(transaction_uuid,product_id,item_name,qty,unit,original_price,discount_type,discount_value,discount_amount,subtotal)
      VALUES(@transaction_uuid,@product_id,@item_name,@qty,@unit,@original_price,@discount_type,@discount_value,@discount_amount,@subtotal)`);
    const stock=d.prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=?');
    for(const i of sale.items){ ii.run({transaction_uuid:sale.uuid,product_id:i.product_id,item_name:i.item_name,qty:i.qty,unit:i.unit||'pcs',original_price:i.original_price,discount_type:i.discount_type||'none',discount_value:i.discount_value||0,discount_amount:i.discount_amount||0,subtotal:i.subtotal}); stock.run(i.qty,i.product_id); }
    d.exec('COMMIT');
  } catch (e) { try { d.exec('ROLLBACK'); } catch {} throw e; }
  return sale;
}
function pendingSales(){ return getDb().prepare("SELECT * FROM transactions WHERE status IN ('pending','error') ORDER BY created_at ASC LIMIT 100").all().map(s=>({...s,items:getDb().prepare('SELECT * FROM transaction_items WHERE transaction_uuid=? ORDER BY id').all(s.uuid)})); }
function markSynced(uuid,res){ getDb().prepare("UPDATE transactions SET status='synced',server_sale_id=?,server_sale_no=?,sync_error=NULL,synced_at=? WHERE uuid=?").run(res.sale_id||null,res.sale_no||null,new Date().toISOString(),uuid); }
function markSyncError(uuid,msg){ getDb().prepare("UPDATE transactions SET status='error',sync_error=? WHERE uuid=?").run(String(msg||'Gagal sinkron').slice(0,1000),uuid); }
function listSales(limit=200){ return getDb().prepare('SELECT * FROM transactions ORDER BY created_at DESC LIMIT ?').all(limit); }
function saleDetail(uuid){ const h=getDb().prepare('SELECT * FROM transactions WHERE uuid=?').get(uuid); if(!h)return null; h.items=getDb().prepare('SELECT * FROM transaction_items WHERE transaction_uuid=? ORDER BY id').all(uuid); return h; }
module.exports={initDb,getDb,setMeta,getMeta,replaceMaster,listProducts,listCustomers,createSale,pendingSales,markSynced,markSyncError,listSales,saleDetail};

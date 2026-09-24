'use strict';
const {contextBridge,ipcRenderer}=require('electron');
contextBridge.exposeInMainWorld('pos',{
  session:()=>ipcRenderer.invoke('session:get'), login:(username,password)=>ipcRenderer.invoke('auth:login',{username,password}), logout:()=>ipcRenderer.invoke('auth:logout'),
  products:q=>ipcRenderer.invoke('products:list',q), customers:q=>ipcRenderer.invoke('customers:list',q), createSale:p=>ipcRenderer.invoke('sales:create',p),
  sales:()=>ipcRenderer.invoke('sales:list'), saleDetail:u=>ipcRenderer.invoke('sales:detail',u), sync:()=>ipcRenderer.invoke('sync:run'), syncInfo:()=>ipcRenderer.invoke('sync:info'),
  printReceipt:u=>ipcRenderer.invoke('print:receipt',u), about:()=>ipcRenderer.invoke('app:about'), onSync:fn=>ipcRenderer.on('sync-status',(_e,d)=>fn(d))
});

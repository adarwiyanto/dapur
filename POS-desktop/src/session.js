'use strict';
const fs=require('fs'); const path=require('path'); const {safeStorage}=require('electron');
let file;
function init(userData){ file=path.join(userData,'session.dat'); }
function save(obj){ if(!file)throw new Error('Session belum diinisialisasi'); const raw=Buffer.from(JSON.stringify(obj)); const out=safeStorage.isEncryptionAvailable()?safeStorage.encryptString(raw.toString('utf8')):raw; fs.writeFileSync(file,out,{mode:0o600}); }
function load(){ try{ const b=fs.readFileSync(file); const s=safeStorage.isEncryptionAvailable()?safeStorage.decryptString(b):b.toString('utf8'); return JSON.parse(s); }catch{return null;} }
function clear(){ try{fs.unlinkSync(file);}catch{} }
module.exports={init,save,load,clear};

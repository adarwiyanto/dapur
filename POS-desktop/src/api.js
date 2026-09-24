'use strict';
const {API_BASE,REQUEST_TIMEOUT_MS}=require('./config');
async function request(path,{method='GET',token,body}={}){
  const controller=new AbortController(); const timer=setTimeout(()=>controller.abort(),REQUEST_TIMEOUT_MS);
  try{
    const headers={'Accept':'application/json','Content-Type':'application/json'}; if(token) headers.Authorization=`Bearer ${token}`;
    const res=await fetch(API_BASE+path,{method,headers,body:body?JSON.stringify(body):undefined,signal:controller.signal});
    const text=await res.text(); let data={}; try{data=text?JSON.parse(text):{};}catch{throw new Error(`Respons server tidak valid (${res.status})`);}
    if(!res.ok || data.ok===false) throw new Error(data.error||data.message||`HTTP ${res.status}`); return data;
  } finally { clearTimeout(timer); }
}
const login=(username,password,device_id)=>request('/login.php',{method:'POST',body:{username,password,device_id}});
const bootstrap=(token)=>request('/bootstrap.php',{token});
const pushSale=(token,sale,device_id)=>request('/sales.php',{method:'POST',token,body:{...sale,device_id}});
const health=()=>request('/health.php');
module.exports={login,bootstrap,pushSale,health};

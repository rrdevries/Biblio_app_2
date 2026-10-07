import {readWorkWindows} from './book-search-contract.js';
const PREFIX = 'biblio.search.return.v1.';
const KEY = /^[a-zA-Z0-9-]{16,64}$/;
export function getSearchStorage(){try{return globalThis.sessionStorage;}catch{return null;}}
export function clearSearchReturns(storage = getSearchStorage()) {
    try { for (let i=storage.length-1;i>=0;i--) { const key=storage.key(i); if (key?.startsWith(PREFIX)) storage.removeItem(key); } } catch {}
}
export function saveSearchReturn(record, {storage=getSearchStorage(), random=()=>globalThis.crypto.randomUUID()}={}) {
    try {
        const key=random(); if (!KEY.test(key)) return null;
        // Caller supplies bibliography only. Explicitly exclude private projection state.
        const {query,search_context,groups,isbn,view,selected,parent,scrollY,focusId,tab,resultPosition,searchVersion,workWindows,workRevision}=record;
        if(searchVersion===2)readWorkWindows(workWindows,workRevision);
        const value={version:1,query,search_context,groups,isbn,view,selected,parent,scrollY,focusId,tab,resultPosition,searchVersion,workWindows,workRevision};
        const json=JSON.stringify(value);if(json.length>262144)return null;storage.setItem(PREFIX+key,json);return key;
    } catch { return null; }
}
export function readSearchReturn(key, storage=getSearchStorage()) {
    try {
        if (!KEY.test(key??'')) return null;
        const json=storage.getItem(PREFIX+key);if(typeof json!=='string'||json.length>262144)return null;const record=JSON.parse(json);
        if (record?.version!==1 || typeof record.query!=='string' || typeof record.search_context!=='string') return null;
        return record;
    } catch { return null; }
}
export function searchReturnUrl(searchUrl, key, storage) {
    if (!readSearchReturn(key,storage)) return null;
    try { const url=new URL(searchUrl); url.search='';url.hash='';url.searchParams.set('search_return',key);return url.toString(); } catch { return null; }
}

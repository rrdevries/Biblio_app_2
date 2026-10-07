import test from 'node:test';
import assert from 'node:assert/strict';
import {createGlobalBookSearchApp,readBookSearchResponse,normalizeStandaloneQuery,safeSourceUrl} from '../../assets/js/global-book-search.js';
import {saveSearchReturn,readSearchReturn,clearSearchReturns,searchReturnUrl} from '../../assets/js/search-return.js';
import {readSearchPresence,readSearchCatalogs,readSearchDetails,readSearchDescription} from '../../assets/js/book-search-contract.js';
const group=items=>({items,next_cursor:null,provider_attempts:[]});
const work=(id='work-one')=>({result_id:id,result_selector:`signed-${id}`,title:id,authors:[],series:[]});
const response=(query='Book',items=[work()])=>({version:1,query:{type:'text',normalized:query},search_context:'signed-context',expires_at:'2026-10-07T12:00:00Z',state:'results',text_results:{query,authors:group([]),works:group(items)},isbn_results:null});
class MemoryStorage{constructor(){this.values=new Map();}get length(){return this.values.size;}key(i){return [...this.values.keys()][i];}getItem(k){return this.values.get(k)??null;}setItem(k,v){this.values.set(k,v);}removeItem(k){this.values.delete(k);}}
class Element{
 constructor(tag){this.tagName=tag;this.children=[];this.attributes={};this.listeners={};this.dataset={};this.className='';this.classList={add:(c)=>this.className+=' '+c};this.value='';}
 append(...children){this.children.push(...children);}replaceChildren(...children){this.children=children;}
 setAttribute(k,v){this.attributes[k]=v;if(k==='href')this.href=v;}getAttribute(k){return this.attributes[k]??null;}
 addEventListener(k,v){this.listeners[k]=v;}removeEventListener(k){delete this.listeners[k];}focus(){this.focused=true;}
 querySelectorAll(){return [];}click(){return this.listeners.click?.({target:this});}
}
const nodes=n=>[n,...n.children.flatMap(nodes)];const text=n=>nodes(n).map(v=>v.textContent??'').join(' ');
const flush=async()=>{for(let i=0;i<10;i++)await new Promise(r=>setImmediate(r));};
function mount(api,options={}){
 const root=new Element('div');root.dataset={overviewUrl:'https://biblio.test/catalogus/',searchUrl:'https://biblio.test/zoeken/',loginUrl:'https://biblio.test/login/',accountState:'authenticated'};
 const content=new Element('main');root.append(content);
 const app=createGlobalBookSearchApp({root,api,documentImpl:{createElement:tag=>new Element(tag)},eventTarget:{scrollY:0},shellFactory:()=>({contentRoot:content,destroy(){}}),storage:new MemoryStorage(),locationImpl:{href:'https://biblio.test/zoeken/'},scrollToImpl(){},...options});
 return {root,content,app,input:nodes(root).find(n=>n.tagName==='input'),form:nodes(root).find(n=>n.tagName==='form')};
}
test('typed standalone envelope separates ISBN from Work and source links are fixed hosts',()=>{
 assert.equal(readBookSearchResponse(response()).query.type,'text');assert.throws(()=>readBookSearchResponse({...response(),isbn_results:{items:[]}}));assert.equal(normalizeStandaloneQuery('  song   of ice  '),'song of ice');assert.equal(safeSourceUrl('https://openlibrary.org/works/OL1W'),true);assert.equal(safeSourceUrl('https://openlibrary.org.evil.test/'),false);assert.equal(safeSourceUrl('javascript:alert(1)'),false);
});
test('standalone decoders reject nested private fields, write capabilities and extra envelope fields',()=>{
 assert.throws(()=>readBookSearchResponse({...response(),secret:'extra'}));
 assert.throws(()=>readBookSearchResponse(response('Book',[{...work(),can_add_work_only:true}])));
 assert.throws(()=>readSearchPresence({items:[{result_id:'work-one',identity_scope:'work',state:'present',library_name:'PRIVATE'}]}));
 assert.throws(()=>readSearchCatalogs({identity_scope:'work',state:'unknown',items:[],next_cursor:null,access_scope:null,registrations:[]}));
 assert.throws(()=>readSearchDetails({result_id:'work-one',entity_type:'work',book:{...work(),authors:[{author_id:null,display_name:'Author',user_id:'PRIVATE'}]},edition:null}));
 assert.throws(()=>readSearchDescription({state:'available',text:'Text',language:null,language_basis:null,provenance:null,truncated:false}));
});
test('return storage excludes private projections and degrades safely when unavailable',()=>{
 const storage=new MemoryStorage();const key=saveSearchReturn({query:'Book',search_context:'signed',groups:{works:group([work()])},presence:[{library_name:'PRIVATE'}],catalogs:[{item_id:'SECRET'}]},{storage,random:()=> 'return-key-123456789'});
 assert.ok(readSearchReturn(key,storage));assert.equal(JSON.stringify(readSearchReturn(key,storage)).includes('PRIVATE'),false);assert.equal(JSON.stringify(readSearchReturn(key,storage)).includes('SECRET'),false);assert.match(searchReturnUrl('https://biblio.test/zoeken/',key,storage),/search_return=/);clearSearchReturns(storage);assert.equal(readSearchReturn(key,storage),null);
 assert.equal(saveSearchReturn({},{storage:{setItem(){throw new Error('quota');}},random:()=> 'return-key-123456789'}),null);
});
test('raw spaces stay in the field, stale responses cannot replace the latest search and Wissen clears',async()=>{
 const pending=[];const api={post(path,body){if(path.endsWith('book-searches'))return new Promise(resolve=>pending.push({body,resolve}));return Promise.resolve({items:[]});}};
 const m=mount(api);m.input.value='a song of ice ';m.form.listeners.submit({preventDefault(){}});assert.equal(m.input.value,'a song of ice ');
 m.input.value='New book ';m.form.listeners.submit({preventDefault(){}});pending[1].resolve(response('New book',[work('latest')]));await flush();pending[0].resolve(response('a song of ice',[work('stale')]));await flush();assert.match(text(m.root),/latest/);assert.doesNotMatch(text(m.root),/stale/);assert.equal(m.input.value,'New book ');
 nodes(m.root).find(n=>n.tagName==='button'&&n.textContent==='Wissen').click();assert.equal(m.input.value,'');assert.doesNotMatch(text(m.root),/latest/);m.app.destroy();
});
test('a late projection from a previous Work is ignored and catalog presence is one quiet badge',async()=>{
 let deferred;const api={post(path,body){if(path.endsWith('book-searches'))return Promise.resolve(response('Book',[work('first'),work('second')]));if(path.endsWith('book-search-presence'))return Promise.resolve({items:[{result_id:'first',identity_scope:'work',state:'present'},{result_id:'second',identity_scope:'work',state:'unknown'}]});if(path.endsWith('book-search-details'))return Promise.resolve({result_id:body.result_selector==='signed-first'?'first':'second',entity_type:'work',book:{...work(body.result_selector==='signed-first'?'first':'second')},edition:null});if(path.endsWith('book-search-descriptions')){if(body.result_selector==='signed-first')return new Promise(resolve=>deferred=resolve);return Promise.resolve({state:'unavailable',text:null,language:null,language_basis:null,provenance:null,truncated:false});}if(path.endsWith('book-search-catalogs'))return Promise.resolve({identity_scope:'work',state:'unknown',items:[],next_cursor:null,access_scope:null});if(path.endsWith('book-search-editions'))return Promise.resolve(group([]));}};
 const m=mount(api);m.input.value='Book';m.form.listeners.submit({preventDefault(){}});await flush();assert.equal(nodes(m.root).filter(n=>n.textContent==='In catalogus').length,1);
 nodes(m.root).find(n=>n.id==='open-first').click();await flush();nodes(m.root).find(n=>n.textContent==='Terug naar zoekresultaten').click();await flush();nodes(m.root).find(n=>n.id==='open-second').click();await flush();deferred({state:'available',text:'Old private text',language:null,language_basis:null,provenance:{provider_key:'open_library',record_id:'/works/OL1W',scope:'work',source_url:'https://openlibrary.org/works/OL1W',retrieved_at:'2026-10-07T00:00:00Z'},truncated:false});await flush();assert.doesNotMatch(text(m.root),/Old private text/);assert.match(text(m.root),/Inhoudsomschrijving nog niet beschikbaar/);m.app.destroy();
});
test('return validates on the server before bibliography is rendered, and expired records offer re-search',async()=>{
 const storage=new MemoryStorage();const key=saveSearchReturn({query:'Book',search_context:'old',groups:{authors:group([]),works:group([work('unvalidated')])},isbn:null,view:'results'},{storage,random:()=> 'return-key-123456789'});let reject;
 const m=mount({post(){return new Promise((_,r)=>reject=r);}},{storage,locationImpl:{href:`https://biblio.test/zoeken/?search_return=${key}`},historyImpl:{replaceState(){}}});assert.doesNotMatch(text(m.root),/unvalidated/);reject(Object.assign(new Error('expired'),{code:'biblio_book_search_context_unavailable',status:409}));await m.app.ready;assert.match(text(m.root),/Zoekcontext verlopen/);assert.equal(m.input.value,'Book');assert.equal(storage.length,0);m.app.destroy();
});
test('temporary return validation failure retains only bibliography for a deliberate retry',async()=>{
 const storage=new MemoryStorage();const key=saveSearchReturn({query:'Book',search_context:'old',groups:{authors:group([]),works:group([work('unvalidated')])},isbn:null,view:'results'},{storage,random:()=> 'return-key-123456789'});let fail=true;
 const m=mount({post(path){if(path.endsWith('/validate')){if(fail){fail=false;return Promise.reject(Object.assign(new Error('temporary'),{status:503}));}return Promise.resolve({valid:true});}return Promise.resolve({items:[]});}},{storage,locationImpl:{href:`https://biblio.test/zoeken/?search_return=${key}`},historyImpl:{replaceState(){}}});await m.app.ready;assert.doesNotMatch(text(m.root),/unvalidated/);assert.match(text(m.root),/kon niet worden gecontroleerd/);assert.equal(storage.length,1);await nodes(m.root).find(n=>n.textContent==='Opnieuw proberen').click();await flush();assert.match(text(m.root),/unvalidated/);m.app.destroy();
});

const sourceWork=(order,id=`source-${order}`,local=false)=>({...work(id),presentation_order:order,result_kind:local?'local_canonical':'external_candidate'});
const sourceGroup=(items=[],source_state='complete',next_cursor=null,retry_cursor=null)=>({items,source_state,next_cursor,retry_cursor,provider_attempts:[]});
const v2=(works,requested_group='all')=>({...response('Book'),version:2,requested_group,state:['incomplete','failed'].includes(works?.source_state)?(works.items.length?'partial_failure':'failure'):'results',text_results:{query:'Book',authors:requested_group==='works'?null:group([]),works:requested_group==='authors'?null:works}});
test('v2 typed boundaries distinguish incomplete empty windows, groups and opaque recovery',()=>{
 assert.equal(readBookSearchResponse(v2(sourceGroup([],'incomplete','next','retry'))).state,'failure');
 for(const bad of [v2(sourceGroup([],'incomplete','next',null)),{...v2(sourceGroup()),requested_group:'authors'},v2(sourceGroup([work()])),v2({...sourceGroup(),raw_offset:10}),{...response(),requested_group:'all'}])assert.throws(()=>readBookSearchResponse(bad));
 assert.equal(readBookSearchResponse(v2(null,'authors')).text_results.works,null);
});
test('incomplete ledger survives more pages; retries restore original order without rolling back continuation',async()=>{
 const bodies=[];let retry=0;
 const api={post(path,body){if(!path.endsWith('book-searches'))return Promise.resolve({items:[]});bodies.push(body);
  if(!body.result_group)return Promise.resolve(v2(sourceGroup([sourceWork(0)],'incomplete','next-10','retry-0')));
  if(body.work_cursor==='next-10')return Promise.resolve(v2(sourceGroup([sourceWork(10)],'incomplete','next-20','retry-10'),'works'));
  if(body.work_cursor==='next-20')return Promise.resolve(v2(sourceGroup([sourceWork(20)],'complete','next-30'),'works'));
  if(body.work_cursor==='retry-0'){if(retry++===0)return Promise.reject(new Error('network'));return Promise.resolve(v2(sourceGroup([sourceWork(0),sourceWork(1)],'complete','next-10'),'works'));}
  if(body.work_cursor==='retry-10')return Promise.resolve(v2(sourceGroup([sourceWork(10),sourceWork(11)],'complete','next-20'),'works'));
  throw new Error('Unexpected cursor');}};
 const m=mount(api);m.input.value='Book';m.form.listeners.submit({preventDefault(){}});await flush();
 const click=async id=>{nodes(m.root).find(n=>n.id===id).click();await flush();};const retryClick=async()=>{nodes(m.root).find(n=>n.textContent==='Opnieuw proberen').click();await flush();};
 await click('more-works');await click('more-works');assert.equal(nodes(m.root).filter(n=>n.textContent==='Niet alle zoekresultaten konden worden getoond.').length,1);
 await retryClick();assert.match(text(m.root),/source-20/);await retryClick();assert.match(text(m.root),/Niet alle/);await retryClick();assert.doesNotMatch(text(m.root),/Niet alle/);
 assert.deepEqual(nodes(m.root).filter(n=>n.tagName==='h3').map(n=>n.textContent),['source-0','source-1','source-10','source-11','source-20']);
 assert.deepEqual(bodies.map(b=>b.work_cursor),[undefined,'next-10','next-20','retry-0','retry-0','retry-10']);
 assert.equal(bodies.slice(1).every(b=>b.result_group==='works'),true);m.app.destroy();
});
test('all rejected window offers More and retry, never an ordinary miss; double clicks are one flight',async()=>{
 let finish;let requests=0;const api={post(path){if(!path.endsWith('book-searches'))return Promise.resolve({items:[]});requests++;if(requests===1)return Promise.resolve(v2(sourceGroup([],'incomplete','next','retry')));return new Promise(r=>finish=r);}};
 const m=mount(api);m.input.value='Book';m.form.listeners.submit({preventDefault(){}});await flush();assert.doesNotMatch(text(m.root),/Geen boeken gevonden/);
 const more=nodes(m.root).find(n=>n.id==='more-works');more.click();more.click();assert.equal(requests,2);finish(v2(sourceGroup([sourceWork(10)]),'works'));await flush();assert.match(text(m.root),/Niet alle/);m.app.destroy();
});
test('legacy active Work cursor restores with retained query and Opnieuw zoeken',async()=>{
 const storage=new MemoryStorage();const works={...group([work('legacy')]),next_cursor:'legacy-work-cursor'};const key=saveSearchReturn({query:'Book',search_context:'signed',groups:{authors:group([]),works},isbn:null,view:'results'},{storage,random:()=> 'return-key-123456789'});
 const m=mount({post(){return Promise.resolve({valid:true});}},{storage,locationImpl:{href:`https://biblio.test/zoeken/?search_return=${key}`},historyImpl:{replaceState(){}}});await m.app.ready;assert.match(text(m.root),/Opnieuw zoeken/);assert.equal(m.input.value,'Book');assert.doesNotMatch(text(m.root),/legacy/);m.app.destroy();
});
test('temporary ledger has a strict storage bound and is retained on safe return',async()=>{
 const storage=new MemoryStorage();const g={authors:group([]),works:sourceGroup([sourceWork(0)],'incomplete','next-10','retry-0')};
 const record={query:'Book',search_context:'signed',groups:g,isbn:null,view:'results',searchVersion:2,workWindows:[{retry_cursor:'retry-0',source_state:'incomplete',revision:0}],workRevision:0};
 const key=saveSearchReturn(record,{storage,random:()=> 'return-key-123456789'});assert.ok(key);
 const m=mount({post(){return Promise.resolve({valid:true});}},{storage,locationImpl:{href:`https://biblio.test/zoeken/?search_return=${key}`},historyImpl:{replaceState(){}}});await m.app.ready;assert.match(text(m.root),/Niet alle zoekresultaten/);m.app.destroy();
 assert.equal(saveSearchReturn({...record,workWindows:Array.from({length:101},(_,i)=>({retry_cursor:`retry-${i}`,source_state:'incomplete',revision:0}))},{storage,random:()=> 'return-key-123456789'}),null);
 assert.equal(saveSearchReturn({...record,groups:{tooLarge:'x'.repeat(262145)}},{storage,random:()=> 'return-key-123456789'}),null);
});

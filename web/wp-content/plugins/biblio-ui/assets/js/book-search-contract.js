const object=(v,allowed,required=[])=>{if(v===null||typeof v!=='object'||Array.isArray(v)||Object.keys(v).some(k=>!allowed.includes(k))||required.some(k=>!Object.hasOwn(v,k)))throw new TypeError('Ongeldig zoekantwoord.');return v;};
const str=(v,nullable=false)=>{if(!(typeof v==='string'&&v.length<=65536)&&!(nullable&&v===null))throw new TypeError('Ongeldige zoektekst.');};
const strings=v=>{if(!Array.isArray(v)||v.length>100||v.some(x=>typeof x!=='string'))throw new TypeError('Ongeldige zoeklijst.');};
const AUTHORS=['result_id','result_kind','author_id','display_name','author_selector','result_selector','match_quality','name_group_id','disambiguation'];
const WORKS=['result_id','result_kind','work_id','work_selector','result_selector','provider_identity','title','authors','series','presentation_order'];
const EDITIONS=['result_id','result_kind','edition_id','result_selector','provider_identity','provider_work_identity','parent_work_result_id','work_id','title','subtitle','contributors','languages','publishers','publication_date','isbn_10','isbn_13','format','page_count','presentation_order'];
function identity(v){if(v===null)return;object(v,['provider_key','record_id'],['provider_key','record_id']);str(v.provider_key);str(v.record_id);}
function common(v){str(v.result_id);if('result_selector'in v)str(v.result_selector);if('result_kind'in v&&!['local_canonical','external_candidate'].includes(v.result_kind))throw new TypeError('Ongeldige identiteit.');}
export function readWork(v,{selected=true}={}){
 object(v,WORKS,['result_id','title','authors','series',...(selected?['result_selector']:[])]);common(v);str(v.title);
 if('presentation_order'in v&&(!Number.isInteger(v.presentation_order)||v.presentation_order<0||v.presentation_order>1000000))throw new TypeError();
 if('work_id'in v)str(v.work_id,true);if('work_selector'in v)str(v.work_selector);if('provider_identity'in v)identity(v.provider_identity);
 if(!Array.isArray(v.authors)||!Array.isArray(v.series))throw new TypeError();
 for(const a of v.authors){object(a,['author_id','display_name'],['author_id','display_name']);str(a.author_id,true);str(a.display_name);}
 for(const s of v.series){object(s,['series_id','display_name','position'],['series_id','display_name','position']);str(s.series_id,true);str(s.display_name);str(s.position,true);}return v;
}
export function readEdition(v,{selected=true}={}){
 object(v,EDITIONS,['result_id','edition_id','title','subtitle','contributors','languages','publishers','publication_date','isbn_10','isbn_13','format','page_count',...(selected?['result_selector']:[])]);common(v);str(v.edition_id,true);str(v.title);
 for(const key of ['subtitle','publication_date','isbn_10','isbn_13','format'])str(v[key],true);
 for(const key of ['contributors','languages','publishers'])strings(v[key]);
 if(!(v.page_count===null||(Number.isInteger(v.page_count)&&v.page_count>0)))throw new TypeError();
 for(const key of ['provider_identity','provider_work_identity'])if(key in v)identity(v[key]);if('work_id'in v)str(v.work_id,true);if('parent_work_result_id'in v)str(v.parent_work_result_id);if('presentation_order'in v&&(!Number.isInteger(v.presentation_order)||v.presentation_order<0))throw new TypeError();return v;
}
function author(v){object(v,AUTHORS,AUTHORS);common(v);for(const key of ['display_name','author_selector','match_quality','name_group_id'])str(v[key]);str(v.author_id,true);const d=object(v.disambiguation,['representative_work_title','linked_work_count','birth_year'],['representative_work_title','linked_work_count','birth_year']);str(d.representative_work_title,true);for(const key of ['linked_work_count','birth_year'])if(!(d[key]===null||Number.isInteger(d[key])))throw new TypeError();return v;}
function attempts(values){if(!Array.isArray(values))throw new TypeError();for(const a of values){object(a,['provider_key','status','failure_reason'],['provider_key','status','failure_reason']);str(a.provider_key);str(a.status);str(a.failure_reason,true);}}
export function readSearchGroup(v,scope='work',{standalone=false}={}){
 const fields=['items','next_cursor','provider_attempts',...(standalone?['source_state','retry_cursor']:[])];
 object(v,fields,fields);if(!Array.isArray(v.items))throw new TypeError();
 for(const item of v.items){(scope==='author'?author:scope==='edition'?readEdition:readWork)(item);
  if(scope==='work'&&(standalone?(!Object.hasOwn(item,'presentation_order')||!['local_canonical','external_candidate'].includes(item.result_kind)):Object.hasOwn(item,'presentation_order')))throw new TypeError();}
 str(v.next_cursor,true);attempts(v.provider_attempts);
 if(standalone){if(!['complete','incomplete','failed','not_checked'].includes(v.source_state))throw new TypeError();str(v.retry_cursor,true);if(['incomplete','failed'].includes(v.source_state)!==(v.retry_cursor!==null))throw new TypeError();if(v.source_state==='failed'&&v.next_cursor!==null)throw new TypeError();}return v;
}
export function readBookSearchResponse(v){
 const fields=['version','query','search_context','expires_at','state','text_results','isbn_results',...(v?.version===2?['requested_group']:[])];object(v,fields,fields);
 if(![1,2].includes(v.version)||!['results','no_results','partial_failure','failure'].includes(v.state))throw new TypeError();object(v.query,['type','normalized'],['type','normalized']);str(v.query.normalized);str(v.search_context);str(v.expires_at);
 const requested=v.version===1?'all':v.requested_group;if(!['all','authors','works'].includes(requested))throw new TypeError();
 if(v.query.type==='text'){if(v.isbn_results!==null)throw new TypeError();object(v.text_results,['query','authors','works'],['query','authors','works']);str(v.text_results.query);if(v.text_results.query!==v.query.normalized)throw new TypeError();
  if(requested==='works'){if(v.text_results.authors!==null)throw new TypeError();}else readSearchGroup(v.text_results.authors,'author');
  if(requested==='authors'){if(v.text_results.works!==null)throw new TypeError();}else readSearchGroup(v.text_results.works,'work',{standalone:v.version===2});}
 else if(v.query.type==='isbn'){if(requested!=='all'||v.text_results!==null)throw new TypeError();object(v.isbn_results,['items','provider_attempts'],['items','provider_attempts']);if(!Array.isArray(v.isbn_results.items))throw new TypeError();v.isbn_results.items.forEach(r=>readEdition(r));attempts(v.isbn_results.provider_attempts);}else throw new TypeError();return v;
}
export function readWorkWindows(values,revision){
 if(!Array.isArray(values)||values.length>100||!Number.isInteger(revision)||revision<0)throw new TypeError();const seen=new Set();
 for(const w of values){object(w,['retry_cursor','source_state','revision'],['retry_cursor','source_state','revision']);str(w.retry_cursor);if(!w.retry_cursor||seen.has(w.retry_cursor)||!['incomplete','failed'].includes(w.source_state)||!Number.isInteger(w.revision)||w.revision<0||w.revision>revision)throw new TypeError();seen.add(w.retry_cursor);}return values;
}

export function readSearchDetails(v){object(v,['result_id','entity_type','book','edition'],['result_id','entity_type','book','edition']);str(v.result_id);if(!['work','edition'].includes(v.entity_type))throw new TypeError();if(v.book!==null)readWork(v.book,{selected:false});if(v.edition!==null)readEdition(v.edition,{selected:false});if((v.entity_type==='work'&&v.book===null)||(v.entity_type==='edition'&&v.edition===null))throw new TypeError();return v;}
export function readSearchCatalogs(v){
 object(v,['identity_scope','state','items','next_cursor','access_scope'],['identity_scope','state','items','next_cursor','access_scope']);if(!['work','edition'].includes(v.identity_scope)||!['present','no_confirmed','unknown'].includes(v.state)||!Array.isArray(v.items))throw new TypeError();str(v.next_cursor,true);str(v.access_scope,true);
 for(const r of v.items){object(r,['library_id','library_name','item_id','edition_id','edition'],['library_id','library_name','item_id','edition_id','edition']);for(const k of ['library_id','library_name','item_id','edition_id'])str(r[k]);readEdition(r.edition,{selected:false});}return v;
}
export function readSearchDescription(v){
 object(v,['state','text','language','language_basis','provenance','truncated'],['state','text','language','language_basis','provenance','truncated']);if(!['available','unavailable','failure'].includes(v.state)||typeof v.truncated!=='boolean')throw new TypeError();str(v.text,true);str(v.language,true);str(v.language_basis,true);
 if(v.state==='available'&&(v.text===null||v.provenance===null))throw new TypeError();if(v.state!=='available'&&(v.text!==null||v.provenance!==null))throw new TypeError();
 if(v.provenance!==null){object(v.provenance,['provider_key','record_id','scope','source_url','retrieved_at'],['provider_key','record_id','scope','source_url','retrieved_at']);for(const x of Object.values(v.provenance))str(x);}return v;
}
export function readSearchPresence(v){object(v,['items'],['items']);if(!Array.isArray(v.items)||v.items.length>50)throw new TypeError();for(const r of v.items){object(r,['result_id','identity_scope','state'],['result_id','identity_scope','state']);str(r.result_id);if(!['work','edition'].includes(r.identity_scope)||!['present','no_confirmed','unknown','failure'].includes(r.state))throw new TypeError();}return v;}

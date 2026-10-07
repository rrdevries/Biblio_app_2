import {readBookSearchResponse,readSearchGroup,readSearchDetails,readSearchCatalogs,readSearchDescription,readSearchPresence,readWorkWindows} from './book-search-contract.js';
export {readBookSearchResponse,readSearchGroup} from './book-search-contract.js';
import {createBiblioApi} from './api.js';
import {createLibraryShell} from './ui-shell.js';
import {clearSearchReturns,saveSearchReturn,readSearchReturn,getSearchStorage} from './search-return.js';

const PRESENCE_FAILURE='We konden niet controleren welke boeken al in een toegankelijke bibliotheek staan.';
const isRecord = v => v !== null && typeof v==='object' && !Array.isArray(v);
const selectable = v => isRecord(v) && typeof v.result_id==='string' && typeof v.result_selector==='string' && (typeof v.title==='string'||typeof v.display_name==='string');
export function mergeSearchItems(oldItems,newItems) {
    const ids=new Set();return [...oldItems,...newItems].filter(v=>{if(ids.has(v.result_id))return false;ids.add(v.result_id);return true;});
}
export function mergeWorkItems(oldItems,newItems){
 const items=mergeSearchItems(oldItems,newItems);
 return items.every(r=>Number.isInteger(r.presentation_order))?items.sort((a,b)=>(a.result_kind==='local_canonical'?0:1)-(b.result_kind==='local_canonical'?0:1)||a.presentation_order-b.presentation_order||a.result_id.localeCompare(b.result_id)):items;
}
export function normalizeStandaloneQuery(raw) { return String(raw).trim().replace(/\s+/gu,' '); }
const failed = group => group?.provider_attempts?.some(a=>a.failure_reason!==null) === true;

export function createGlobalBookSearchApp({root,api,documentImpl=globalThis.document,eventTarget=globalThis,
    shellFactory=createLibraryShell,storage=getSearchStorage(),locationImpl=globalThis.location,
    historyImpl=globalThis.history,scrollToImpl=(...args)=>globalThis.scrollTo?.(...args),random}={}) {
    const doc=documentImpl;
    const node=(tag,text,cls,attrs={})=>{ const n=doc.createElement(tag);if(text!=null)n.textContent=text;if(cls)n.className=cls;for(const [k,v]of Object.entries(attrs))n.setAttribute(k,String(v));return n; };
    const button=(text,action,primary=false)=>{const n=node('button',text,`biblio-ui__control${primary?' biblio-ui__control--primary':''}`,{type:'button'});n.id='search-control-'+text.toLowerCase().replace(/[^a-z0-9]+/g,'-');n.addEventListener('click',action);return n;};
    const shell=shellFactory(root,{documentImpl:doc,eventTarget,overviewUrl:root.dataset.overviewUrl,platformUrl:root.dataset.platformUrl,searchUrl:root.dataset.searchUrl,wishlistUrl:root.dataset.wishlistUrl,nextReadingUrl:root.dataset.nextReadingUrl,loginUrl:root.dataset.loginUrl,accountState:root.dataset.accountState,accountName:root.dataset.accountName,logoutUrl:root.dataset.logoutUrl,activeDestination:'search'});
    const view=node('section',null,'biblio-ui__view biblio-ui__bibliographic-search biblio-ui__global-search');
    const title=node('h1','Zoeken in Biblio','biblio-ui__page-title',{tabindex:'-1'});
    const eyebrow=node('p','ONTDEKKEN','biblio-ui__eyebrow');
    const intro=node('p','Vind een boek of auteur in Biblio en de aangesloten boekbronnen. Je ziet bij een boek of het in een toegankelijke catalogus staat.','biblio-ui__search-intro');
    const byline=node('p',null,'biblio-ui__global-search-byline');
    const back=button('Terug naar zoekresultaten',()=>{if(state.parent){const parent=state.parent;state.parent=null;openSelection(parent,'work');}else backResults();});
    back.classList.add('biblio-ui__search-back');
    const header=node('header',null,'biblio-ui__search-page-header');header.append(back,eyebrow,title,byline,intro);
    const form=node('form',null,'biblio-ui__bibliographic-search-form',{role:'search'});
    const label=node('label','Zoek op titel, auteur of ISBN',null,{for:'biblio-book-query'});
    const field=node('div',null,'biblio-ui__bibliographic-search-field');
    const input=node('input',null,null,{id:'biblio-book-query',name:'query',type:'search',maxlength:300,autocomplete:'off',placeholder:'Titel, auteur of ISBN','aria-describedby':'biblio-book-query-help'});
    const submit=node('button','Zoeken','biblio-ui__control biblio-ui__control--primary',{type:'submit'});
    const clear=button('Wissen',()=>reset());field.append(input,submit,clear);
    const help=node('p','Zoek vanaf twee tekens.','biblio-ui__search-help',{id:'biblio-book-query-help'});
    form.append(label,field,help);
    const results=node('div',null,'biblio-ui__search-results');
    const status=node('p',null,'biblio-ui__search-help',{role:'status','aria-live':'polite'});
    view.append(header,form,status,results);shell.contentRoot.append(view);
    let state={query:'',search_context:null,groups:null,isbn:null,view:'results',selected:null,parent:null,tab:'all'};
    let generation=0,selectionRevision=0,presenceRevision=0,controllers=new Set(),presence=new Map(),presenceError=false,loading=false,destroyed=false;
    let detail=null,projections={},restorePosition=null,resultPosition=null,descriptionExpanded=false,retryRestore=null;
    const expandedLibraries=new Set();
    const projectionRevisions={};
    const groupPending=new Set();
    const groupErrors=new Set();
    const abortAll=()=>{generation++;selectionRevision++;groupPending.clear();groupErrors.clear();for(const c of controllers)c.abort();controllers.clear();};
    async function request(path,body,{selected=false}={}) {
        const revision=generation,sel=selectionRevision,c=new AbortController();controllers.add(c);
        try {const value=await api.post(`me/${path}`,body,{signal:c.signal});if(destroyed||c.signal.aborted||revision!==generation||(selected&&sel!==selectionRevision))throw Object.assign(new Error('aborted'),{kind:'aborted'});return value;}
        catch(e){if(e.code==='biblio_book_search_context_unavailable'){abortAll();clearSearchReturns(storage);state={query:input.value,view:'expired'};presence.clear();detail=null;projections={};render();throw Object.assign(e,{kind:'aborted'});}if(e.status===401||e.status===403){abortAll();clearSearchReturns(storage);state={query:input.value,view:'unauthorized'};presence.clear();detail=null;projections={};render();}throw e;}
        finally{controllers.delete(c);}
    }
    const selectionBody=()=>({search_context:state.search_context,result_selector:state.selected.result_selector});
    function reset(){abortAll();state={query:'',search_context:null,groups:null,isbn:null,view:'results',selected:null,parent:null,tab:'all'};presence.clear();presenceError=false;projections={};detail=null;loading=false;input.value='';status.textContent='';render();input.focus();}
    async function search(group=null,mode='append') {
        if(group&&groupPending.has(group))return;
        const query=group?state.query:normalizeStandaloneQuery(input.value);
        if(query.length<2){status.textContent='Voer minimaal twee tekens in.';render();input.focus();return;}
        if(group)groupPending.add(group);
        if(!group){abortAll();state={query,search_context:null,groups:null,isbn:null,view:'results',selected:null,parent:null,tab:'all',workWindows:[],workRevision:0};presence.clear();presenceError=false;detail=null;projections={};loading=true;}
        const run=generation,prior=group?state.groups[group]:null;
        const window=group==='works'&&mode==='retry'?state.workWindows?.[0]:null;
        const cursor=group?(window?.retry_cursor??prior.next_cursor):null;
        const body={query};
        if(group){body.search_context=state.search_context;body.result_group=group;if(cursor)body[group==='authors'?'author_cursor':'work_cursor']=cursor;}
        status.textContent=group?(mode==='retry'?'Resultaten opnieuw controleren…':'Meer resultaten laden…'):'Zoeken…';submit.disabled=loading;render();
        try {
            const response=readBookSearchResponse(await request('book-searches',body));
            if(group&&response.version===2&&response.requested_group!==group)throw new TypeError();
            if(group){
                const next=response.text_results[group];
                if(group==='works'&&response.version===2){
                    const hardFailure=next.source_state==='failed';
                    const canAdvance=mode==='append'||(window&&window.revision===(state.workRevision??0));
                    const nextCursor=!hardFailure&&canAdvance?next.next_cursor:prior.next_cursor;
                    if(mode==='append'&&!hardFailure)state.workRevision=(state.workRevision??0)+1;
                    else if(mode==='retry'&&canAdvance&&!hardFailure&&nextCursor!==prior.next_cursor)state.workRevision=(state.workRevision??0)+1;
                    state.groups.works={...next,items:mergeWorkItems(prior.items,next.items),next_cursor:nextCursor};
                    updateWindow(next,window);
                }else state.groups[group]={...next,items:mergeSearchItems(prior.items,next.items),next_cursor:failed(next)&&prior.next_cursor?prior.next_cursor:next.next_cursor};
                groupErrors.delete(group);
            } else{
                if(response.version===2&&response.requested_group!=='all')throw new TypeError();
                state.search_context=response.search_context;state.groups=response.text_results;state.isbn=response.isbn_results;state.searchState=response.state;state.searchVersion=response.version;
                if(response.version===2&&response.text_results)updateWindow(response.text_results.works);
            }
            status.textContent=!group&&response.state==='no_results'?'Geen resultaten gevonden.':'';
            if(!group&&response.state==='failure'&&!response.text_results&&!response.isbn_results)state.searchFailed=true;
            render();await loadPresence();
        } catch(e){if(e.kind!=='aborted'&&state.view!=='unauthorized'&&run===generation){status.textContent='';if(group){groupErrors.add(group);if(group==='works'&&cursor&&!window&&state.searchVersion===2&&!state.workWindows.some(w=>w.retry_cursor===cursor))state.workWindows.push({retry_cursor:cursor,source_state:'failed',revision:state.workRevision??0});}else state.searchFailed=true;render();}}
        finally{if(run===generation){loading=false;submit.disabled=false;if(group)groupPending.delete(group);render();}}
    }
    function updateWindow(next,target=null){
        const windows=state.workWindows??=[];
        if(target){const i=windows.indexOf(target);if(i>=0){if(['complete','not_checked'].includes(next.source_state))windows.splice(i,1);else windows[i]={...target,retry_cursor:next.retry_cursor,source_state:next.source_state};}}
        else if(next.retry_cursor&&!windows.some(w=>w.retry_cursor===next.retry_cursor))windows.push({retry_cursor:next.retry_cursor,source_state:next.source_state,revision:state.workRevision??0});
    }
    async function loadPresence() {
        const items=state.view==='author'?(projections.author?.items??[]):state.view==='book'?(projections.editions?.items??[]):state.isbn?.items??state.groups?.works?.items??[];
        if(!items.length||!state.search_context)return;
        const run=generation,revision=++presenceRevision;presenceError=false;presence.clear();render();
        try {
            const newPresence=new Map(presence);
            for(let offset=0;offset<items.length;offset+=50){const response=readSearchPresence(await request('book-search-presence',{search_context:state.search_context,result_selectors:items.slice(offset,offset+50).map(v=>v.result_selector)}));if(!Array.isArray(response.items))throw new TypeError();for(const item of response.items){if(!['present','no_confirmed','unknown','failure'].includes(item.state))throw new TypeError();newPresence.set(item.result_id,item.state);if(item.state==='failure')presenceError=true;}}
            if(run!==generation||revision!==presenceRevision)return;presence=newPresence;render();
        } catch(e){if(e.kind!=='aborted'&&state.view!=='unauthorized'&&run===generation&&revision===presenceRevision){presence.clear();presenceError=true;render();}}
    }
    function retryBox(message,action,owner='presence'){const box=node('div',null,'biblio-ui__search-partial',{role:'alert'});const retry=button('Opnieuw proberen',action);retry.id='retry-'+owner+'-'+message.toLowerCase().replace(/[^a-z0-9]+/g,'-');box.append(node('p',message),retry);return box;}
    function metadata(record){const lines=[];if(record.subtitle)lines.push(record.subtitle);if(record.languages?.length)lines.push(`Taal: ${record.languages.join(', ')}`);if(record.publication_date)lines.push(`Publicatie: ${record.publication_date}`);if(record.format)lines.push(record.format);if(record.isbn_13||record.isbn_10)lines.push(`ISBN: ${record.isbn_13??record.isbn_10}`);return lines;}
    function card(record,scope,index) {
        const card=node('article',null,'biblio-ui__global-search-card');
        card.append(node('h3',record.title??record.display_name));
        const names=record.authors?.map(a=>a.display_name)??record.contributors??[];
        if(names.length)card.append(node('p',names.join(', '),'biblio-ui__search-authors'));
        if(record.series?.length)card.append(node('p',record.series.map(s=>s.display_name+(s.position?` · ${s.position}`:'')).join(' · ')));
        if(scope==='author'){
            const d=record.disambiguation;if(d?.representative_work_title)card.append(node('p',`Bekend van ${d.representative_work_title}`));
            if(d?.birth_year)card.append(node('p',`Geboren ${d.birth_year}`));
        }
        if(scope==='edition')for(const line of metadata(record))card.append(node('p',line,'biblio-ui__search-help'));
        if(presence.get(record.result_id)==='present')card.append(node('span','In catalogus','biblio-ui__search-presence'));
        const open=button(scope==='author'?'Bekijk boeken':scope==='work'?'Bekijk boek':'Bekijk uitgave',()=>openSelection(record,scope));
        open.id=`open-${record.result_id}`;card.append(open);return card;
    }
    function group(name,label,scope) {
        const g=state.groups[name],section=node('section',null,'biblio-ui__search-result-group');section.append(node('h2',label));
        const list=node('div',null,'biblio-ui__global-search-cards');g.items.forEach((r,i)=>list.append(card(r,scope,i)));section.append(list);
        const windows=name==='works'?(state.workWindows??[]):[];
        const incomplete=windows.some(w=>w.source_state==='incomplete');
        const unavailable=windows.some(w=>w.source_state==='failed')||failed(g)||groupErrors.has(name);
        if(!g.items.length&&!incomplete&&!unavailable)section.append(node('p',`Geen ${label.toLowerCase()} gevonden.`));
        if(incomplete||unavailable){const box=retryBox(incomplete?'Niet alle zoekresultaten konden worden getoond.':`${label} uit een boekbron konden niet worden geladen.`,()=>search(name,'retry'),name);for(const b of box.children)if(b.tagName?.toLowerCase()==='button')b.disabled=groupPending.has(name);section.append(box);}
        if(g.next_cursor){const more=button('Meer laden',()=>search(name,'append'));more.id=`more-${name}`;more.disabled=groupPending.has(name);section.append(more);}return section;
    }
    function render(){
        const focusId=results.contains?.(doc.activeElement)?doc.activeElement?.id:null;
        const restoreFocus=()=>{if(focusId)doc.getElementById?.(focusId)?.focus?.({preventScroll:true});};
        const descriptionDisclosure=results.querySelector?.('#search-description-disclosure');
        if(descriptionDisclosure)descriptionExpanded=descriptionDisclosure.open;
        for(const disclosure of results.querySelectorAll?.('[data-library-items]')??[]) {
            const id=disclosure.getAttribute('data-library-items');
            if(disclosure.open)expandedLibraries.add(id);else expandedLibraries.delete(id);
        }
        results.replaceChildren();
        const selected=['book','edition','author'].includes(state.view);
        const record=state.view==='edition'?detail?.edition:state.view==='book'?detail?.book:state.selected;
        view.setAttribute('data-search-view',state.view);
        back.hidden=!selected;back.textContent=state.parent?'Terug naar boekoverzicht':'Terug naar zoekresultaten';
        eyebrow.textContent=state.view==='book'?'BOEKOVERZICHT':state.view==='edition'?'UITGAVE':state.view==='author'?'AUTEUR':'ONTDEKKEN';
        title.textContent=selected?(record?.title??record?.display_name??state.selected?.title??state.selected?.display_name):'Zoeken in Biblio';
        byline.textContent=(record?.authors?.map(a=>a.display_name)??record?.contributors??[]).join(', ');
        byline.hidden=!selected||!byline.textContent;
        intro.hidden=selected||Boolean(state.groups||state.isbn);
        form.hidden=state.view==='book'||state.view==='edition';
        status.hidden=!status.textContent;
        if(state.view==='unauthorized'){status.textContent='';results.append(node('h2','Log in om te zoeken'),node('p','Je sessie is verlopen of je bent niet aangemeld.'));const a=node('a','Inloggen','biblio-ui__control biblio-ui__control--primary',{href:root.dataset.loginUrl});results.append(a);return;}
        if(state.view==='expired'){results.append(node('h2','Zoekcontext verlopen'),node('p','De eerdere resultaten kunnen niet meer veilig worden hersteld.'),button('Opnieuw zoeken',()=>search()));return;}
        if(state.view==='restoreFailure'){results.append(retryBox('Eerdere zoekopdracht kon niet worden gecontroleerd.',()=>restore(retryRestore),'restore'));return;}
        if(state.view==='book'||state.view==='edition'){renderDetail();restoreFocus();return;}
        if(state.view==='author'){renderAuthor();restoreFocus();return;}
        if(state.searchFailed)results.append(retryBox('Een deel van het zoeken is niet gelukt.',()=>{state.searchFailed=false;search();}));
        if(presenceError)results.append(retryBox(PRESENCE_FAILURE,loadPresence));
        if(state.isbn){results.append(node('h2',`Uitgaven voor ISBN ${state.query}`));const list=node('div',null,'biblio-ui__global-search-cards');state.isbn.items.forEach((r,i)=>list.append(card(r,'edition',i)));results.append(list);if(!state.isbn.items.length&&state.searchState!=='failure')results.append(node('p','Geen uitgaven gevonden.'));if(failed(state.isbn))results.append(retryBox('De boekbronnen konden niet worden geladen.',()=>search()));}
        else if(state.groups){
            const tabs=node('div',null,'biblio-ui__search-tab-list',{role:'group','aria-label':'Resultaatcategorie'});
            for(const [key,text]of [['all','Alles'],['books','Boeken'],['authors','Auteurs']]){const b=button(text,()=>{state.tab=key;render();});b.setAttribute('aria-pressed',String(state.tab===key));b.classList.add('biblio-ui__search-tab');if(state.tab===key)b.classList.add('biblio-ui__search-tab--active');tabs.append(b);}results.append(tabs);
            if(state.tab!=='authors')results.append(group('works','Boeken','work'));if(state.tab!=='books')results.append(group('authors','Auteurs','author'));
        } else if(!loading)results.append(node('h2','Welk boek zoek je?'),node('p','Zoek een titel, auteur of ISBN. Zoeken in Biblio staat los van de catalogus van één bibliotheek.'));
        applyRestorePosition();restoreFocus();
    }
    async function openSelection(record,scope,{restore=false}={}) {
        selectionRevision++;for(const c of controllers)c.abort();controllers.clear();detail=null;projections={};descriptionExpanded=false;presenceError=false;
        if(!restore){if(state.view==='results')resultPosition={scrollY:eventTarget.scrollY??0,focusId:`open-${record.result_id}`};state.parent=scope==='edition'&&state.view==='book'?state.selected:null;}
        state.selected=record;state.view=scope==='work'?'book':scope;status.textContent='';
        if(scope==='author'){projections.author={state:'loading',items:[]};render();loadAuthor();return;}
        render();
        try{detail=readSearchDetails(await request('book-search-details',selectionBody(),{selected:true}));render();loadProjection('description');loadProjection('catalogs');if(scope==='work')loadProjection('editions');}
        catch(e){if(e.kind!=='aborted'&&state.view!=='unauthorized'){detail={error:true};render();}}
        if(!restore){title.focus();scrollToImpl(0,0);}
    }
    function backResults(){selectionRevision++;for(const c of controllers)c.abort();controllers.clear();state.view='results';state.selected=null;state.parent=null;detail=null;projections={};presence.clear();restorePosition=resultPosition;render();loadPresence();}
    async function loadAuthor(more=false){
        const revision=(projectionRevisions.author??0)+1;projectionRevisions.author=revision;
        const prior=projections.author??{items:[]};projections.author={...prior,state:'loading'};render();
        try{const p=readSearchGroup(await request('book-search-author-works',{...selectionBody(),...(more?{cursor:prior.next_cursor}:{})},{selected:true}));if(revision!==projectionRevisions.author)return;projections.author={...p,items:more?mergeSearchItems(prior.items,p.items):p.items,next_cursor:more&&failed(p)?prior.next_cursor:p.next_cursor,state:failed(p)?'failure':'ready'};render();loadPresence();}
        catch(e){if(e.kind!=='aborted'&&state.view!=='unauthorized'&&revision===projectionRevisions.author){projections.author={...prior,state:'failure'};render();}}
    }
    function renderAuthor(){if(presenceError)results.append(retryBox(PRESENCE_FAILURE,loadPresence));results.append(node('h2','Boeken van deze auteur'));const p=projections.author;if(p?.state==='loading')results.append(node('p','Boeken laden…',null,{role:'status'}));const list=node('div',null,'biblio-ui__global-search-cards');(p?.items??[]).forEach((r,i)=>list.append(card(r,'work',i)));results.append(list);if(p?.state==='failure')results.append(retryBox('Boeken konden niet worden geladen.',()=>loadAuthor(Boolean(p.next_cursor))));if(p?.next_cursor){const more=button('Meer laden',()=>loadAuthor(true));more.id='more-author-works';more.disabled=p.state==='loading';results.append(more);}}
    async function loadProjection(name,more=false){
        const revision=(projectionRevisions[name]??0)+1;projectionRevisions[name]=revision;
        const prior=name==='catalogs'&&!more?{}:projections[name]??{};projections[name]={...prior,state:'loading'};render();
        const endpoint={description:'book-search-descriptions',catalogs:'book-search-catalogs',editions:'book-search-editions'}[name];
        try{
            const p=await request(endpoint,{...selectionBody(),...(more?{cursor:prior.next_cursor}:{})},{selected:true});
            if(revision!==projectionRevisions[name])return;
            if(name==='editions')readSearchGroup(p,'edition');
            if(name==='catalogs')readSearchCatalogs(p);
            if(name==='description')readSearchDescription(p);
            projections[name]={...p,...(more&&name==='editions'&&failed(p)?{next_cursor:prior.next_cursor}:{}),...(more?{items:name==='catalogs'?[...(prior.items??[]),...(p.items??[]).filter(r=>!(prior.items??[]).some(x=>x.library_id===r.library_id&&x.item_id===r.item_id))]:mergeSearchItems(prior.items??[],p.items??[])}:{}),identity_state:name==='catalogs'?p.state:null,state:name==='description'?p.state:name==='editions'&&failed(p)?'failure':'ready'};render();if(name==='editions')loadPresence();
        }catch(e){if(revision!==projectionRevisions[name])return;if(name==='catalogs'&&e.code==='biblio_book_search_access_changed'){projections.catalogs={};expandedLibraries.clear();render();loadProjection('catalogs');return;}if(e.kind!=='aborted'&&state.view!=='unauthorized'){projections[name]={...prior,state:'failure'};render();}}
    }
    function renderDetail(){
        const record=state.view==='edition'?detail?.edition:detail?.book;
        if(presenceError)results.append(retryBox(PRESENCE_FAILURE,loadPresence));
        if(!detail){results.append(node('p','Boekgegevens laden…',null,{role:'status'}));return;}
        if(detail.error){results.append(retryBox('Boekgegevens konden niet worden geladen.',()=>openSelection(state.selected,state.view==='book'?'work':'edition',{restore:true})));return;}
        if(record?.series?.length)results.append(node('p',record.series.map(s=>s.display_name+(s.position?` · ${s.position}`:'')).join(' · ')));
        if(state.view==='edition')for(const line of metadata(record??{}))results.append(node('p',line));
        const description=node('section',null,'biblio-ui__global-search-section');description.append(node('h2','Inhoudsomschrijving'));
        const d=projections.description;
        if(!d||d.state==='loading')description.append(node('p','Inhoudsomschrijving laden…',null,{role:'status'}));
        else if(d.state==='failure')description.append(retryBox('Inhoudsomschrijving kon niet worden geladen',()=>loadProjection('description')));
        else if(d.state==='unavailable')description.append(node('p','Inhoudsomschrijving nog niet beschikbaar.'));
        else{
            const paragraphs=d.text.split(/\n\s*\n/);description.append(node('p',paragraphs[0]));
            if(paragraphs.length>1){const more=node('details');more.id='search-description-disclosure';more.open=descriptionExpanded;const summary=node('summary','Meer lezen');summary.id='search-description-more';more.append(summary);for(const text of paragraphs.slice(1))more.append(node('p',text));description.append(more);}
            const source=node('p',null,'biblio-ui__search-help');source.append(node('span',`${d.language?`Taal: ${d.language}`:'Taal onbekend'} · `));
            const provenance=d.provenance;if(provenance&&safeSourceUrl(provenance.source_url))source.append(node('a',provenance.provider_key==='open_library'?'Open Library':'Google Books',null,{href:provenance.source_url,target:'_blank',rel:'noopener noreferrer'}));description.append(source);if(d.truncated)description.append(node('p','De brontekst is ingekort.','biblio-ui__search-help'));
        }results.append(description);
        renderCatalogs();if(state.view==='book')renderEditions();applyRestorePosition();
    }
    function renderCatalogs(){
        const section=node('section',null,'biblio-ui__global-search-section');section.append(node('h2','In toegankelijke catalogi'));const p=projections.catalogs;
        if(!p||p.state==='loading')section.append(node('p','Catalogi controleren…',null,{role:'status'}));
        if(p?.state==='failure')section.append(retryBox(PRESENCE_FAILURE,()=>loadProjection('catalogs',Boolean(p.next_cursor)),'catalogs'));
        const groups=new Map();for(const row of p?.items??[]){if(!groups.has(row.library_id))groups.set(row.library_id,{name:row.library_name,items:[]});groups.get(row.library_id).items.push(row);}
        for(const [id,g]of groups){const box=node('section',null,'biblio-ui__global-search-library');box.append(node('h3',g.name));const disclosure=node('details',null,null,{'data-library-items':id});disclosure.open=expandedLibraries.has(id);const summary=node('summary','Exemplaren bekijken');summary.id=`catalog-disclosure-${id}`;disclosure.append(summary);for(const item of g.items){const row=node('div',null,'biblio-ui__global-search-item');row.append(node('p',item.edition?.title??'Exemplaar'));for(const line of metadata(item.edition??{}))row.append(node('p',line,'biblio-ui__search-help'));const a=node('a','Bekijk exemplaar','biblio-ui__control',{href:itemUrl(item)});a.addEventListener('click',e=>{const key=saveSearchReturn({...state,resultPosition,scrollY:eventTarget.scrollY??0,focusId:`item-${item.item_id}`},{storage,random});if(key){const url=new URL(a.href);url.searchParams.set('search_return',key);a.href=url.toString();}else{e.preventDefault?.();state.view='expired';render();}});a.id=`item-${item.item_id}`;row.append(a);disclosure.append(row);}box.append(disclosure);section.append(box);}
        if(p?.state==='ready'&&!p.items.length)section.append(node('p',p.identity_state==='unknown'?'We kunnen nog niet vaststellen of dit boek in een toegankelijke catalogus staat.':p.identity_scope==='edition'?'Geen actief exemplaar van deze uitgave aangetoond in je toegankelijke catalogi.':'Geen actief exemplaar aangetoond in je toegankelijke catalogi.'));
        if(p?.next_cursor){const more=button('Meer laden',()=>loadProjection('catalogs',true));more.id='more-catalogs';more.disabled=p.state==='loading';section.append(more);}results.append(section);
    }
    function itemUrl(item){const url=new URL(root.dataset.overviewUrl);url.search='';url.hash='';url.searchParams.set('library_id',item.library_id);url.searchParams.set('item_id',item.item_id);return url.toString();}
    function renderEditions(){const section=node('section',null,'biblio-ui__global-search-section');section.append(node('h2','Uitgaven'));const p=projections.editions;if(!p||p.state==='loading')section.append(node('p','Uitgaven laden…',null,{role:'status'}));const list=node('div',null,'biblio-ui__global-search-cards');(p?.items??[]).forEach((r,i)=>list.append(card(r,'edition',i)));section.append(list);if(p?.state==='ready'&&!p.items.length)section.append(node('p','Geen uitgaven gevonden.'));if(p?.state==='failure')section.append(retryBox('Uitgaven konden niet worden geladen.',()=>loadProjection('editions',Boolean(p.next_cursor))));if(p?.next_cursor){const more=button('Meer laden',()=>loadProjection('editions',true));more.id='more-editions';more.disabled=p.state==='loading';section.append(more);}results.append(section);}
    function applyRestorePosition(){if(!restorePosition)return;const target=doc.getElementById?.(restorePosition.focusId);if(target){const disclosure=target.closest?.('details');if(disclosure)disclosure.open=true;target.focus?.({preventScroll:true});}scrollToImpl(0,Number(restorePosition.scrollY)||0);if(target||state.view==='results')restorePosition=null;}
    async function restore(savedRecord=null){
        let key;try{key=new URL(locationImpl.href).searchParams.get('search_return');}catch{return;}
        if(!key&&!savedRecord)return;
        const record=savedRecord??readSearchReturn(key,storage);
        // Consume the explicit return flag. A refresh does not automatically restore search.
        try{const url=new URL(locationImpl.href);url.searchParams.delete('search_return');historyImpl.replaceState(null,'',url.toString());}catch{}
        if(!record){state.view='expired';render();return;}
        input.value=record.query;status.textContent='Eerdere zoekopdracht controleren…';
        try{
            await request('book-search-contexts/validate',{search_context:record.search_context});
            if(!isRecord(record.groups)&&!isRecord(record.isbn))throw new TypeError();
            if(record.isbn)readBookSearchResponse({version:1,query:{type:'isbn',normalized:record.query},search_context:record.search_context,expires_at:'restored',state:'results',text_results:null,isbn_results:record.isbn});
            if(record.groups){
                for(const name of ['authors','works'])readSearchGroup(record.groups[name],name==='authors'?'author':'work',{standalone:name==='works'&&record.searchVersion===2});
                if(record.searchVersion===2)readWorkWindows(record.workWindows,record.workRevision);
                else if(record.groups.works.next_cursor!==null)throw new TypeError('Legacy Work progress must be restarted.');
            }
            state={query:record.query,search_context:record.search_context,groups:record.groups,isbn:record.isbn,view:'results',selected:record.selected,parent:record.parent,tab:record.tab??'all',searchVersion:record.searchVersion??1,workWindows:record.workWindows??[],workRevision:record.workRevision??0};presence.clear();status.textContent='';resultPosition=record.resultPosition??null;restorePosition={scrollY:record.scrollY,focusId:record.focusId};
            if(['book','edition','author'].includes(record.view)&&selectable(record.selected))await openSelection(record.selected,record.view==='book'?'work':record.view,{restore:true});else render();
            loadPresence();
        }catch(e){if(e.kind!=='aborted'&&state.view!=='unauthorized'){
            if(e instanceof TypeError){clearSearchReturns(storage);state={query:record.query,view:'expired'};}
            else{retryRestore=record;state={query:record.query,view:'restoreFailure'};}
            status.textContent='';render();
        }}
    }
    const onSubmit=e=>{e.preventDefault();search();};form.addEventListener('submit',onSubmit);
    const onLogout=e=>{const link=e.target?.closest?.('a');if(link?.href===root.dataset.logoutUrl)clearSearchReturns(storage);};root.addEventListener('click',onLogout);
    if(root.dataset.accountState!=='authenticated'){state.view='unauthorized';clearSearchReturns(storage);}
    render();const ready=state.view==='unauthorized'?Promise.resolve():restore();
    return {ready,destroy(){destroyed=true;abortAll();form.removeEventListener('submit',onSubmit);root.removeEventListener('click',onLogout);shell.destroy();root.replaceChildren();}};
}
export function safeSourceUrl(value){try{const u=new URL(value);return u.protocol==='https:'&&['openlibrary.org','books.google.com'].includes(u.hostname)&&!u.username&&!u.password;}catch{return false;}}
function bootstrap(){for(const root of document.querySelectorAll('[data-biblio-search-root]'))createGlobalBookSearchApp({root,api:createBiblioApi({restRoot:root.dataset.restRoot,restNonce:root.dataset.restNonce})});}
if(typeof document!=='undefined'){if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bootstrap,{once:true});else bootstrap();}

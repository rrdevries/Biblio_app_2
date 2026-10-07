import {expect,test} from '@playwright/test';
const work=(order,local=false)=>({result_id:`source-${order}`,result_kind:local?'local_canonical':'external_candidate',work_id:local?`work-${order}`:null,work_selector:`legacy-${order}`,result_selector:`signed-${order}`,title:`Bronboek ${order}`,authors:[],series:[],presentation_order:order});
const group=(items,state='complete',next=null,retry=null)=>({items,source_state:state,next_cursor:next,retry_cursor:retry,provider_attempts:[]});
const answer=(works,requested_group='all')=>({version:2,requested_group,query:{type:'text',normalized:'bronboek'},search_context:'source-context',expires_at:'2099-01-01T00:00:00Z',state:['incomplete','failed'].includes(works?.source_state)?(works.items.length?'partial_failure':'failure'):'results',text_results:{query:'bronboek',authors:requested_group==='works'?null:{items:[],next_cursor:null,provider_attempts:[]},works},isbn_results:null});
for(const [width,blocked] of [[1440,false],[400,false],[400,true]])test(`partial source progress, retry and return at ${width}px${blocked?' with unavailable storage':''}`,async({page})=>{
 await page.setViewportSize({width,height:900});const bodies=[];let retry0=0;let fail10=true;const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/wp-json/biblio/v1/me/book-search**',async route=>{
  const path=new URL(route.request().url()).pathname.split('/me/')[1],body=route.request().postDataJSON();let data;
  if(path==='book-searches'){
   bodies.push(body);
   if(!body.result_group)data=answer(group([work(0)],'incomplete','next-10','retry-0'));
   else if(body.work_cursor==='next-10'){
    if(fail10){fail10=false;return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({code:'temporary',message:'Temporary',data:{status:503}})});}
    data=answer(group([work(10)],'complete','next-20'),'works');
   }else if(body.work_cursor==='next-20')data=answer(group([work(20)],'incomplete',null,'retry-20'),'works');
   else if(body.work_cursor==='retry-0'){retry0++;data=answer(group([work(0),work(1)],'complete','next-10'),'works');}
   else if(body.work_cursor==='retry-20')data=answer(group([work(20),work(21)],'complete',null),'works');
   else throw new Error(`unexpected ${body.work_cursor}`);
  }else if(path==='book-search-presence')data={items:[]};
  else if(path==='book-search-details')data={result_id:'source-0',entity_type:'work',book:work(0),edition:null};
  else if(path==='book-search-descriptions')data={state:'unavailable',text:null,language:null,language_basis:null,provenance:null,truncated:false};
  else if(path==='book-search-editions')data={items:[],next_cursor:null,provider_attempts:[]};
  else if(path==='book-search-contexts/validate')data={valid:true};
  else if(path==='book-search-catalogs')data={identity_scope:'work',state:'present',items:[{library_id:'e2e-library-actor',library_name:'Testcatalogus',item_id:'e2e-item-primary',edition_id:'e2e-edition-primary',edition:{result_id:'edition',edition_id:'e2e-edition-primary',title:'Fixture exemplaar',subtitle:null,contributors:[],languages:[],publishers:[],publication_date:null,isbn_10:null,isbn_13:null,format:null,page_count:null}}],next_cursor:null,access_scope:'fixture'};
  else throw new Error(path);
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data})});
 });
 await page.goto('/zoeken/');await page.getByRole('searchbox').fill('bronboek');await page.getByRole('button',{name:'Zoeken',exact:true}).click();
 const books=page.locator('.biblio-ui__search-result-group').filter({has:page.getByRole('heading',{name:'Boeken',exact:true})});
 await expect(books.getByText('Niet alle zoekresultaten konden worden getoond.',{exact:true})).toHaveCount(1);
 if(!blocked)await page.screenshot({path:`project/bewijs/SEARCH-SOURCE-03-${width}-onvolledig.png`,fullPage:true});
 await page.locator('#more-works').click();await expect(page.getByRole('heading',{name:'Bronboek 0',exact:true})).toBeVisible();
 // Oldest outstanding window is repaired first; the failed append remains separately recoverable.
 await books.getByRole('button',{name:'Opnieuw proberen'}).click();await expect(page.getByRole('heading',{name:'Bronboek 1',exact:true})).toBeVisible();
 await expect(books.getByText('Boeken uit een boekbron konden niet worden geladen.')).toBeVisible();
 await books.getByRole('button',{name:'Opnieuw proberen'}).click();await expect(page.getByRole('heading',{name:'Bronboek 10',exact:true})).toBeVisible();
 await page.locator('#more-works').click();await expect(page.getByRole('heading',{name:'Bronboek 20',exact:true})).toBeVisible();
 await expect(page.locator('#more-works')).toHaveCount(0);
 // Temporary return context carries unresolved source windows, never catalog projections.
 await page.locator('#open-source-0').click();await page.getByText('Exemplaren bekijken',{exact:true}).click();if(blocked)await page.evaluate(()=>{Storage.prototype.setItem=()=>{throw new Error('quota');};});await page.getByRole('link',{name:'Bekijk exemplaar'}).click();if(blocked){await expect(page.getByRole('button',{name:'Opnieuw zoeken',exact:true})).toBeVisible();await expect(page.getByRole('searchbox')).toHaveValue('bronboek');expect(errors).toEqual([]);return;}await page.getByRole('link',{name:'Terug naar zoeken',exact:true}).click();await page.getByRole('button',{name:'Terug naar zoekresultaten'}).click();
 await expect(books.getByText('Niet alle zoekresultaten konden worden getoond.',{exact:true})).toHaveCount(1);
 await books.getByRole('button',{name:'Opnieuw proberen'}).click();await expect(page.getByRole('heading',{name:'Bronboek 21',exact:true})).toBeVisible();
 await expect(books.getByText('Niet alle zoekresultaten konden worden getoond.',{exact:true})).toHaveCount(0);
 await expect(books.locator('h3')).toHaveText(['Bronboek 0','Bronboek 1','Bronboek 10','Bronboek 20','Bronboek 21']);expect(retry0).toBe(1);
 expect(bodies.slice(1).every(b=>b.result_group==='works'&&b.search_context==='source-context')).toBe(true);expect(bodies.slice(1).map(b=>b.work_cursor)).toEqual(['next-10','retry-0','next-10','next-20','retry-20']);
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);expect(errors).toEqual([]);
 await page.screenshot({path:`project/bewijs/SEARCH-SOURCE-03-${width}-hersteld.png`,fullPage:true});
});
test('entire rejected source window is incomplete and may safely continue',async({page})=>{
 let initial=true;await page.route('**/wp-json/biblio/v1/me/book-search**',async route=>{const path=new URL(route.request().url()).pathname.split('/me/')[1];let data;if(path==='book-searches'){data=answer(initial?group([],'incomplete','next-10','retry-0'):group([work(10)]),initial?'all':'works');initial=false;}else data={items:[]};await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data})});});
 await page.goto('/zoeken/');await page.getByRole('searchbox').fill('bronboek');await page.getByRole('button',{name:'Zoeken',exact:true}).click();await expect(page.getByText('Niet alle zoekresultaten konden worden getoond.')).toBeVisible();await expect(page.getByText('Geen boeken gevonden.',{exact:true})).toHaveCount(0);await page.locator('#more-works').click();await expect(page.getByRole('heading',{name:'Bronboek 10',exact:true})).toBeVisible();await expect(page.getByText('Niet alle zoekresultaten konden worden getoond.')).toBeVisible();
});

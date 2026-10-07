import {expect,test} from '@playwright/test';
const attempt={provider_key:'local',status:'candidates',failure_reason:null};
const group=items=>({items,next_cursor:null,provider_attempts:[attempt]});
const work=(id='work-one',title='Een helder boek')=>({result_id:id,result_kind:'local_canonical',work_id:id,work_selector:'legacy-'+id,result_selector:'signed-'+id,title,authors:[{author_id:'author-one',display_name:'Ada Auteur'}],series:[{series_id:'series-one',display_name:'Een reeks',position:'2'}]});
const author={result_id:'author-one',result_kind:'local_canonical',author_id:'author-one',display_name:'Ada Auteur',author_selector:'legacy-author',result_selector:'signed-author',match_quality:'exact',name_group_id:'group-one',disambiguation:{representative_work_title:'Een helder boek',linked_work_count:1,birth_year:null}};
const edition={result_id:'edition-one',result_kind:'local_canonical',edition_id:'edition-one',result_selector:'signed-edition',work_id:'work-one',title:'Een helder boek — uitgave',subtitle:null,contributors:['Ada Auteur'],languages:['nld'],publishers:['Uitgever'],publication_date:'2020',isbn_10:'0140328726',isbn_13:'9780140328721',format:'hardcover',page_count:120,provider_identity:null,provider_work_identity:null};
const envelope=(query='helder')=>({version:1,query:{type:'text',normalized:query},search_context:'signed-context',expires_at:'2099-01-01T00:00:00Z',state:'results',text_results:{query,authors:group([author]),works:group([work()])},isbn_results:null});
const description={state:'available',text:'Een korte eerste alinea.\n\nEen langere tweede alinea met de rest van de omschrijving.',language:null,language_basis:null,provenance:{provider_key:'open_library',record_id:'/works/OL1W',scope:'work',source_url:'https://openlibrary.org/works/OL1W',retrieved_at:'2026-10-07T00:00:00Z'},truncated:false};
async function mocks(page,{isbn=false,failure=false,revoked=false}={}){
 let accessChanged=false;
 await page.route('**/wp-json/biblio/v1/me/book-search**',async route=>{
  const path=new URL(route.request().url()).pathname.split('/me/')[1];const body=route.request().postDataJSON();let data;
  if(path==='book-searches'){
   data=envelope(body.query);if(isbn)data={...data,query:{type:'isbn',normalized:'9780140328721'},text_results:null,isbn_results:{items:failure?[]:[edition],provider_attempts:failure?[{provider_key:'open_library',status:'unavailable',failure_reason:'network'}]:[attempt]},state:failure?'failure':'results'};
  }else if(path==='book-search-presence')data={items:body.result_selectors.map(selector=>({result_id:selector.replace('signed-',''),identity_scope:selector==='signed-edition'?'edition':'work',state:'present'}))};
  else if(path==='book-search-details')data={result_id:body.result_selector==='signed-edition'?'edition-one':'work-one',entity_type:body.result_selector==='signed-edition'?'edition':'work',book:work(),edition:body.result_selector==='signed-edition'?edition:null};
  else if(path==='book-search-descriptions')data=description;
  else if(path==='book-search-editions')data=group([edition]);
  else if(path==='book-search-author-works')data=group([work()]);
  else if(path==='book-search-contexts/validate')data={valid:true,context_scope:'scope-one',expires_at:'2099-01-01T00:00:00Z'};
  else if(path==='book-search-catalogs'){
   if(revoked&&body.cursor){accessChanged=true;return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({code:'biblio_book_search_access_changed',message:'Access changed.',data:{status:409}})});}
   data={identity_scope:'work',state:accessChanged?'no_confirmed':'present',items:accessChanged?[]:[{library_id:'e2e-library-actor',library_name:'Testcatalogus',item_id:'e2e-item-primary',edition_id:'e2e-edition-primary',edition}],next_cursor:revoked&&!accessChanged?'catalog-next':null,access_scope:accessChanged?'new-scope':'old-scope'};
  }else throw new Error(path);
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data})});
 });
}
const search=async(page,q='helder')=>{await page.goto('/zoeken/');await page.getByRole('searchbox',{name:'Zoek op titel, auteur of ISBN'}).fill(q);await page.getByRole('button',{name:'Zoeken',exact:true}).click();};

test('read-only Work, description, exact Edition, Item and two-level return on desktop and mobile',async({page})=>{
 const errors=[];page.on('pageerror',e=>errors.push(e.message));await mocks(page);await search(page);
 await expect(page.getByText('In catalogus',{exact:true})).toHaveCount(1);
 await expect(page.getByRole('navigation',{name:'Hoofdnavigatie'}).getByRole('link',{name:'Zoeken in Biblio',exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Bekijk boek',exact:true}).click();await expect(page.getByRole('heading',{name:'Een helder boek',exact:true})).toBeVisible();
 await expect(page.getByText('Een korte eerste alinea.',{exact:true})).toBeVisible();await expect(page.getByText('Een langere tweede alinea met de rest van de omschrijving.')).toBeHidden();await page.getByText('Meer lezen',{exact:true}).click();await expect(page.getByText('Een langere tweede alinea met de rest van de omschrijving.')).toBeVisible();
 await expect(page.getByText('Taal onbekend',{exact:false})).toBeVisible();await expect(page.getByRole('link',{name:'Bekijk exemplaar'})).toBeHidden();
 await page.getByRole('button',{name:'Bekijk uitgave',exact:true}).click();await expect(page.getByRole('heading',{name:'Een helder boek — uitgave',exact:true})).toBeVisible();await page.getByRole('button',{name:'Terug naar boekoverzicht'}).click();
 await page.getByText('Exemplaren bekijken',{exact:true}).click();await page.getByRole('link',{name:'Bekijk exemplaar'}).click();await expect(page).toHaveURL(/item_id=e2e-item-primary.*search_return=/);
 await expect(page.getByRole('link',{name:'Terug naar zoeken',exact:true})).toBeVisible();await expect(page.getByRole('link',{name:'Terug naar bibliotheek',exact:true})).toBeVisible();await page.getByRole('link',{name:'Terug naar zoeken',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Een helder boek',exact:true})).toBeVisible();await expect(page.getByRole('link',{name:'Bekijk exemplaar'})).toBeFocused();await page.getByRole('button',{name:'Terug naar zoekresultaten'}).click();await expect(page.getByRole('button',{name:'Bekijk boek',exact:true})).toBeFocused();
 const restoredFocus=await page.getByRole('button',{name:'Bekijk boek',exact:true}).evaluate(el=>({keyboard:el.matches(':focus-visible'),outline:getComputedStyle(el).outlineColor,style:getComputedStyle(el).outlineStyle}));expect(restoredFocus.keyboard?restoredFocus.outline==='rgb(7, 95, 158)':restoredFocus.style==='none').toBe(true);
 await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:'project/bewijs/SEARCH-UI-F1-resultaten-desktop.png',fullPage:true,animations:'disabled'});
 await page.getByRole('button',{name:'Bekijk boek',exact:true}).click();await page.setViewportSize({width:400,height:756});await expect(page.locator('.biblio-ui__sidebar')).toBeHidden();await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:'project/bewijs/SEARCH-UI-F1-boek-mobiel.png',fullPage:true,animations:'disabled'});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
 for(const button of await page.locator('main button:visible,main summary:visible').all()){const box=await button.boundingBox();if(box)expect(box.height).toBeGreaterThanOrEqual(44);}
 expect(errors).toEqual([]);
});

test('ISBN opens exact Edition directly and provider failure is never a successful no-hit',async({page})=>{
 await mocks(page,{isbn:true});await search(page,'0140328726');await expect(page.getByRole('heading',{name:'Uitgaven voor ISBN 0140328726'})).toBeVisible();await expect(page.getByRole('button',{name:'Bekijk boek',exact:true})).toHaveCount(0);await page.getByRole('button',{name:'Bekijk uitgave'}).click();await expect(page.getByRole('heading',{name:'Een helder boek — uitgave',exact:true})).toBeVisible();
 await page.unroute('**/wp-json/biblio/v1/me/book-search**');await mocks(page,{isbn:true,failure:true});await search(page,'9791090636071');await expect(page.getByText('De boekbronnen konden niet worden geladen.')).toBeVisible();await expect(page.getByText('Geen uitgaven gevonden.',{exact:true})).toHaveCount(0);
});

test('catalog paging discards loaded private groups when access changes',async({page})=>{
 await mocks(page,{revoked:true});await search(page);await page.getByRole('button',{name:'Bekijk boek',exact:true}).click();const catalogs=page.locator('section').filter({has:page.getByRole('heading',{name:'In toegankelijke catalogi',exact:true})}).last();await expect(catalogs.getByRole('heading',{name:'Testcatalogus'})).toBeVisible();await catalogs.getByRole('button',{name:'Meer laden'}).click();await expect(catalogs.getByText('Testcatalogus',{exact:true})).toHaveCount(0);await expect(catalogs.getByText('Geen actief exemplaar aangetoond in je toegankelijke catalogi.')).toBeVisible();
});

test('typing keeps spaces, Author works have presence, and Wissen clears only this search',async({page})=>{
 await mocks(page);await page.goto('/zoeken/');const input=page.getByRole('searchbox');await input.pressSequentially('a song of ice and fire ');await expect(input).toHaveValue('a song of ice and fire ');await page.getByRole('button',{name:'Zoeken',exact:true}).click();await expect(input).toHaveValue('a song of ice and fire ');await page.getByRole('button',{name:'Bekijk boeken'}).click();await expect(page.getByText('In catalogus',{exact:true})).toHaveCount(1);await page.getByRole('button',{name:'Wissen',exact:true}).click();await expect(input).toHaveValue('');await expect(page.getByRole('heading',{name:'Welk boek zoek je?'})).toBeVisible();
});

test('live Core and UI search opens the authorized fixture Item and restores with fresh projections',async({page})=>{
 await search(page,'Dagboek van een slecht jaar');const card=page.locator('.biblio-ui__global-search-card').filter({hasText:'Dagboek van een slecht jaar'}).first();await expect(card.getByText('In catalogus',{exact:true})).toBeVisible({timeout:20000});await card.getByRole('button',{name:'Bekijk boek',exact:true}).click();await expect(page.getByRole('heading',{name:'In toegankelijke catalogi',exact:true})).toBeVisible();await page.getByText('Exemplaren bekijken',{exact:true}).first().click();await page.getByRole('link',{name:'Bekijk exemplaar'}).first().click();await expect(page.getByRole('link',{name:'Terug naar zoeken',exact:true})).toBeVisible();await page.getByRole('link',{name:'Terug naar zoeken',exact:true}).click();await expect(page.getByRole('heading',{name:'Dagboek van een slecht jaar',exact:true})).toBeVisible();await expect(page.getByRole('link',{name:'Bekijk exemplaar'}).first()).toBeFocused();
});

test('late catalog response preserves Meer lezen and its keyboard focus',async({page})=>{
 await mocks(page);let finishCatalog;
 await page.route('**/wp-json/biblio/v1/me/book-search-catalogs',async route=>{await new Promise(resolve=>finishCatalog=resolve);await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data:{identity_scope:'work',state:'no_confirmed',items:[],next_cursor:null,access_scope:'current'}})});});
 await search(page);await page.getByRole('button',{name:'Bekijk boek',exact:true}).click();const more=page.locator('#search-description-more');await more.click();await more.focus();finishCatalog();await expect(page.getByText('Catalogi controleren…')).toHaveCount(0);await expect(page.locator('#search-description-disclosure')).toHaveAttribute('open','');await expect(more).toBeFocused();
});

test('independent group cursors keep loaded cards through a failed page and retry the same cursor',async({page})=>{
 await mocks(page);const bodies=[];let failWork=true;
 await page.route('**/wp-json/biblio/v1/me/book-searches',async route=>{
  const b=route.request().postDataJSON();bodies.push(b);const data=envelope(b.query);
  if(b.work_cursor){if(failWork){failWork=false;return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({code:'biblio_internal_error',message:'Temporary failure',data:{status:500}})});}data.text_results.works=group([work('work-two','Nog een boek')]);}
  else if(b.author_cursor)data.text_results.authors=group([{...author,result_id:'author-two',result_selector:'signed-author-two',author_id:'author-two',display_name:'Andere Auteur'}]);
  else{data.text_results.works.next_cursor='work-next';data.text_results.authors.next_cursor='author-next';}
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data})});
 });
 await search(page);await page.locator('#more-authors').click();await expect(page.getByRole('heading',{name:'Andere Auteur'})).toBeVisible();await page.locator('#more-works').click();await expect(page.getByRole('heading',{name:'Een helder boek',exact:true})).toBeVisible();await page.locator('.biblio-ui__search-result-group').filter({has:page.getByRole('heading',{name:'Boeken',exact:true})}).getByRole('button',{name:'Opnieuw proberen'}).click();await expect(page.getByRole('heading',{name:'Nog een boek'})).toBeVisible();expect(bodies.filter(b=>b.work_cursor).map(b=>b.work_cursor)).toEqual(['work-next','work-next']);expect(bodies.filter(b=>b.author_cursor)).toHaveLength(1);
});


test('corrected hierarchy, active categories, compact cards and blue keyboard focus',async({page})=>{
 await page.setViewportSize({width:1440,height:1000});await mocks(page);await search(page);
 const root=page.locator('.biblio-ui__global-search');
 expect(await root.getByRole('searchbox').evaluate(el=>parseFloat(getComputedStyle(el).paddingLeft))).toBeGreaterThanOrEqual(16);
 const books=page.getByRole('button',{name:'Boeken',exact:true});await books.click();
 await expect(books).toHaveAttribute('aria-pressed','true');
 const selectedStyle=await books.evaluate(el=>({border:getComputedStyle(el).borderBottomColor,width:getComputedStyle(el).borderBottomWidth}));
 expect(selectedStyle.width).toBe('2px');expect(selectedStyle.border).not.toBe('rgba(0, 0, 0, 0)');
 await expect(page.getByRole('button',{name:'Bekijk boeken',exact:true})).toHaveCount(0);
 const card=page.locator('.biblio-ui__global-search-card').first();expect((await card.boundingBox()).width).toBeLessThan(500);
 await expect(root.locator('.biblio-ui__search-intro')).toBeHidden();
 await page.getByRole('button',{name:'Bekijk boek',exact:true}).click();
 await expect(page.getByRole('heading',{level:1,name:'Een helder boek',exact:true})).toBeVisible();
 await expect(root.getByRole('search')).toBeHidden();await expect(root.locator('.biblio-ui__search-intro')).toBeHidden();
 await expect(page.getByRole('heading',{name:'Uitgaven',exact:true})).toBeVisible();
 const sectionGap=await root.locator('.biblio-ui__global-search-section').evaluateAll(els=>els.slice(1).map((el,i)=>el.getBoundingClientRect().top-els[i].getBoundingClientRect().bottom));expect(sectionGap.every(g=>g>=0&&g<=32)).toBe(true);
 const summary=page.locator('#search-description-more');await page.keyboard.press('Tab');await summary.focus();
 const focus=await summary.evaluate(el=>({visible:el.matches(':focus-visible'),color:getComputedStyle(el).outlineColor,width:getComputedStyle(el).outlineWidth,token:getComputedStyle(el).getPropertyValue('--biblio-color-focus').trim()}));
 expect(focus.visible).toBe(true);expect(focus.width).toBe('2px');expect(focus.color).toBe('rgb(7, 95, 158)');
 await summary.press('Enter');await page.keyboard.press('Tab');
 await page.screenshot({path:'project/bewijs/SEARCH-UI-F1-boek-desktop.png',fullPage:true,animations:'disabled'});
 await page.setViewportSize({width:400,height:756});await page.screenshot({path:'project/bewijs/SEARCH-UI-F1-boek-mobiel.png',fullPage:true,animations:'disabled'});
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
 await page.getByRole('button',{name:'Terug naar zoekresultaten'}).click();await expect(books).toHaveAttribute('aria-pressed','true');await expect(root.getByRole('search')).toBeVisible();
});

test('a failed Work source shows one recoverable warning while local cards remain',async({page})=>{
 await mocks(page);await page.route('**/wp-json/biblio/v1/me/book-searches',async route=>{
  const data=envelope(route.request().postDataJSON().query);data.state='partial_failure';data.text_results.works.provider_attempts=[attempt,{provider_key:'open_library',status:'invalid_response',failure_reason:'malformed'}];
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data})});
 });
 await search(page);const root=page.locator('.biblio-ui__global-search');
 await expect(root.getByRole('alert')).toHaveCount(1);await expect(root.getByRole('alert').getByRole('button',{name:'Opnieuw proberen'})).toBeVisible();
 await expect(page.getByRole('heading',{name:'Een helder boek',exact:true})).toBeVisible();await expect(root.getByText('Een boekbron kon niet worden geladen.',{exact:true})).toHaveCount(0);
 await page.screenshot({path:'project/bewijs/SEARCH-UI-F1-bronmelding-desktop.png',fullPage:true,animations:'disabled'});
});


test('mouse activation does not outline the selected title, keyboard activation keeps visible focus',async({page})=>{
 await mocks(page);await search(page);
 const open=page.getByRole('button',{name:'Bekijk boek',exact:true});
 await open.click();const title=page.getByRole('heading',{level:1,name:'Een helder boek',exact:true});await expect(title).toBeFocused();
 expect(await title.evaluate(el=>getComputedStyle(el).outlineStyle)).toBe('none');
 await page.screenshot({path:'project/bewijs/SEARCH-UI-F2-titel-muis.png',fullPage:true,animations:'disabled'});
 await page.getByRole('button',{name:'Terug naar zoekresultaten'}).click();await open.focus();await open.press('Enter');await expect(title).toBeFocused();
 const style=await title.evaluate(el=>({color:getComputedStyle(el).outlineColor,width:getComputedStyle(el).outlineWidth,visible:el.matches(':focus-visible')}));
 expect(style).toEqual({color:'rgb(7, 95, 158)',width:'2px',visible:true});
 await page.screenshot({path:'project/bewijs/SEARCH-UI-F2-titel-toetsenbord.png',fullPage:true,animations:'disabled'});
});

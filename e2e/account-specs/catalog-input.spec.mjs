import { expect, test } from '@playwright/test';

// Production app, renderer and CSS; isolated read responses, no account/DB changes.
const fixture = "<!doctype html><html lang=\"nl\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><link rel=\"stylesheet\" href=\"/wp-content/plugins/biblio-ui/assets/css/app.css\"><script type=\"importmap\">{\"imports\": {\"biblio-ui/isbn-scanner\": \"/wp-content/plugins/biblio-ui/assets/js/isbn-scanner.js\", \"biblio-ui/start-reading-view\": \"/wp-content/plugins/biblio-ui/assets/js/start-reading-view.js\", \"biblio-ui/wishlist\": \"/wp-content/plugins/biblio-ui/assets/js/wishlist.js\", \"biblio-ui/bibliographic-search\": \"/wp-content/plugins/biblio-ui/assets/js/bibliographic-search.js\", \"biblio-ui/work-discovery\": \"/wp-content/plugins/biblio-ui/assets/js/work-discovery.js\", \"biblio-ui/next-reading\": \"/wp-content/plugins/biblio-ui/assets/js/next-reading.js\", \"biblio-ui/reading-history\": \"/wp-content/plugins/biblio-ui/assets/js/reading-history.js\", \"biblio-ui/add-book-wizard\": \"/wp-content/plugins/biblio-ui/assets/js/add-book-wizard.js\", \"biblio-ui/end-reading-view\": \"/wp-content/plugins/biblio-ui/assets/js/end-reading-view.js\", \"biblio-ui/ui-shell\": \"/wp-content/plugins/biblio-ui/assets/js/ui-shell.js\", \"biblio-ui/settings-state\": \"/wp-content/plugins/biblio-ui/assets/js/settings-state.js\", \"biblio-ui/catalog-query\": \"/wp-content/plugins/biblio-ui/assets/js/catalog-query.js\", \"biblio-ui/library-state\": \"/wp-content/plugins/biblio-ui/assets/js/library-state.js\", \"biblio-ui/bibliographic-discovery\": \"/wp-content/plugins/biblio-ui/assets/js/bibliographic-discovery.js\", \"biblio-ui/ui-preferences\": \"/wp-content/plugins/biblio-ui/assets/js/ui-preferences.js\", \"biblio-ui/private-notes\": \"/wp-content/plugins/biblio-ui/assets/js/private-notes.js\", \"biblio-ui/api\": \"/wp-content/plugins/biblio-ui/assets/js/api.js\", \"biblio-ui/detail-view\": \"/wp-content/plugins/biblio-ui/assets/js/detail-view.js\", \"biblio-ui/route-state\": \"/wp-content/plugins/biblio-ui/assets/js/route-state.js\", \"biblio-ui/add-book-contracts\": \"/wp-content/plugins/biblio-ui/assets/js/add-book-contracts.js\", \"biblio-ui/app\": \"/wp-content/plugins/biblio-ui/assets/js/app.js\", \"biblio-ui/settings\": \"/wp-content/plugins/biblio-ui/assets/js/settings.js\", \"biblio-ui/overview-view\": \"/wp-content/plugins/biblio-ui/assets/js/overview-view.js\", \"biblio-ui/entry\": \"/wp-content/plugins/biblio-ui/assets/js/entry.js\"}}</script></head><body><div id=\"input-fixture-root\" data-biblio-theme=\"ink\" data-biblio-appearance=\"light\"></div><script type=\"module\">\nimport { createLibraryApp } from '/wp-content/plugins/biblio-ui/assets/js/app.js';\nconst root=document.querySelector('#input-fixture-root');root.setAttribute('data-biblio-ui-root','');\nObject.assign(root.dataset,{restRoot:location.origin+'/wp-json/biblio/v1/',restNonce:'input-fixture',overviewUrl:location.origin+'/__biblio_catalog_input_test__/',libraryHomeUrl:location.origin+'/bibliotheek/',platformUrl:location.origin+'/mijn-biblio/',settingsUrl:location.origin+'/instellingen/',librarySettingsUrl:location.origin+'/bibliotheekinstellingen/',searchUrl:location.origin+'/zoeken/',wishlistUrl:location.origin+'/verlanglijst/',nextReadingUrl:location.origin+'/hierna-lezen/',loginUrl:location.origin+'/wp-login.php'});\nconst library={library_id:'input-fixture',name:'Invoerproef \u2014 fictieve bibliotheek',type:'private',status:'active',designated_personal:true,capabilities:{view_collection:true,add_catalog_item:false,modify_catalog_context:false,manage_classification_terms:false,publish_contribution:false,moderate_contribution:false,use_item_directly:true,receive_internal_loan:false}};\nwindow.catalogRequests=[];\nwindow.app=createLibraryApp(root,{settingsReader:async()=>({library:{library_id:library.library_id,name:library.name},capabilities:{manage_defaults:false},preferences:{catalog_view:{value:null,version:0,effective:'grid',source:'biblio'},catalog_archive_visible:{value:null,version:0,effective:false,source:'biblio'}}}),apiFactory:()=>({async get(path){\nif(path==='me/libraries')return {libraries:[library]};\nif(path.endsWith('/classification-options'))return {library_id:library.library_id,book_types:[],genres:[],subjects:[]};\nwindow.catalogRequests.push(path);await new Promise(resolve=>setTimeout(resolve,80));return {library,items:[],next_cursor:null};\n},post(){throw Error('No writes allowed');}})});\nawait window.app.start();\n</script></body></html>";
for (const width of [1440, 400]) {
    test('paused multiword search preserves spaces and caret at '+width+'px', async ({page}) => {
        const errors=[];
        page.on('pageerror',error=>{ errors.push(error.message); console.log('Fixture error: '+error.message); });
        await page.setViewportSize({width,height:900});
        await page.route('**/__biblio_catalog_input_test__/**',route=>route.fulfill({contentType:'text/html',body:fixture}));
        await page.goto('/__biblio_catalog_input_test__/?library_id=input-fixture');
        const search=page.getByRole('searchbox',{name:'Zoeken in deze bibliotheek'});
        await expect(search).toBeVisible();
        await search.fill('An');
        await expect.poll(()=>page.evaluate(()=>new URL(location.href).searchParams.get('catalog_search'))).toBe('An');
        await page.evaluate(()=>window.app.whenIdle());
        await search.press('End');
        await search.press('Space');
        await page.waitForTimeout(350); // Deliberate user pause beyond the 250ms debounce.
        await expect(search).toHaveValue('An ');
        await expect(search).toBeFocused();
        await search.pressSequentially('Offer from a Gentleman',{delay:300});
        await expect.poll(()=>page.evaluate(()=>new URL(location.href).searchParams.get('catalog_search'))).toBe('An Offer from a Gentleman');
        await page.evaluate(()=>window.app.whenIdle());
        await expect(search).toHaveValue('An Offer from a Gentleman');
        await expect(search).toBeFocused();
        expect(await search.evaluate(el=>el.selectionStart)).toBe('An Offer from a Gentleman'.length);
        await search.evaluate(el=>el.setSelectionRange(2,2));
        await search.press('Space');
        await page.waitForTimeout(450);
        await expect(search).toHaveValue('An  Offer from a Gentleman');
        expect(await search.evaluate(el=>el.selectionStart)).toBe(3);
        await page.getByRole('search').getByRole('button',{name:'Zoekopdracht wissen',exact:true}).click();
        await expect(search).toHaveValue('');
        await expect(search).toBeFocused();
        expect(errors).toEqual([]);
    });
}

import { expect, test } from "@playwright/test";

// Browser-only presentation fixture: real renderer/CSS, no account or Core writes.
const fixture = `<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/wp-content/plugins/biblio-ui/assets/css/app.css"></head><body style="margin:0"><div data-biblio-ui-root data-biblio-theme="ink" data-biblio-appearance="light" style="padding:24px"><div id="catalog-root"></div></div><script type="module">
import { createOverviewView } from '/wp-content/plugins/biblio-ui/assets/js/overview-view.js';
const query = {search:'harry', readingStatuses:['not_read'], authorIds:[], seriesIds:[], locationIds:[], bookTypeIds:[], genreIds:[], subjectIds:[], collectionIds:[], withoutCollection:false, sort:'title', archiveScope:'active_only'};
const item = n => ({item_id:'layout-'+n, work_id:'work-'+n, edition_id:'edition-'+n, title:'Testboek '+n, authors:{state:'known', values:['Testauteur']}, cover_reference:{state:'unknown',value:null}, form:{state:'known',value:'physical_book'},location_or_source:{state:'unknown',value:null},reading_status:'not_read',read_date_known:null,item_status:'active',capabilities:{view_item:true,start_reading:false}});
const options = Array.from({length:6}, (_,i)=>({id:'option-'+i,label:'Testoptie '+i}));
const view = createOverviewView(document.querySelector('#catalog-root'), { overviewUrl:location.href, itemUrl:()=>location.href });
window.renderCase = count => view.render({state:'overview', library:{library_id:'layout-only',name:'Layoutproef — geen echte bibliotheek',type:'private',visibility:'private',capabilities:{add_catalog_item:false,use_item_directly:true,receive_internal_loan:false}},items:Array.from({length:count},(_,i)=>item(i)),nextCursor:null,loadingMore:false,loadMoreError:false,canRetryCursor:false,query,searchDraft:'harry',filterOptions:{bookTypes:options,genres:options,subjects:options},refreshing:false,queryError:false,resultAnnouncement:count+' testboeken geladen.'}, {clearSearch(){},clearFilters(){},setFilter(){},selectView(){}});
window.renderCase(2);
</script></body></html>`;

test("few filtered results stay at the top beside a tall rail in Grid and List", async ({ page }) => {
    const errors = [];
    page.on("pageerror", error => errors.push(error.message));
    await page.route("**/__biblio_catalog_spacing_test__/", route => route.fulfill({contentType:"text/html",body:fixture}));
    for (const width of [1440, 1024, 400]) {
        await page.setViewportSize({width,height:900});
        await page.goto('/__biblio_catalog_spacing_test__/');
        await expect(page.locator('.biblio-ui__catalog-item')).toHaveCount(2);
        await page.evaluate(()=>document.fonts.ready);
        if (width >= 768) await page.getByRole('button',{name:'Filters (1)',exact:true}).click();
        for (const count of [2, 1, 0, 12]) {
            await page.evaluate(count=>window.renderCase(count),count);
            for (const view of count === 0 ? ['empty'] : ['grid','list']) {
                if(view !== 'empty') await page.getByRole('button',{name:view === 'grid'?'Grid':'Lijst',exact:true}).click();
                const geometry = await page.evaluate(() => {
                    const main=document.querySelector('.biblio-ui__catalog-main');
                    const chips=main.querySelector('.biblio-ui__filter-chips');
                    const results=main.querySelector('.biblio-ui__catalog-item, .biblio-ui__empty-state');
                    const chip=chips.querySelector('button');
                    return {top:chip.getBoundingClientRect().top-main.getBoundingClientRect().top,gap:results.getBoundingClientRect().top-chip.getBoundingClientRect().bottom,allowed:parseFloat(getComputedStyle(main).rowGap),overflow:document.documentElement.scrollWidth>innerWidth};
                });
                console.log(JSON.stringify({width,count,view,...geometry}));
                expect(geometry.top).toBeLessThanOrEqual(1);
                expect(geometry.gap).toBeGreaterThanOrEqual(0);
                expect(geometry.gap).toBeLessThanOrEqual(geometry.allowed+1);
                expect(geometry.overflow).toBe(false);
                if (count === 2 || count === 0) await page.screenshot({path:'project/bewijs/V2001-CATALOG-F1-'+width+'-'+view+'.png',fullPage:true,animations:'disabled'});
            }
        }
        if(width < 768) {
            await page.getByRole('button',{name:'Filters (1)',exact:true}).click();
            await expect(page.getByRole('button',{name:'Filters sluiten'})).toBeFocused();
            await page.keyboard.press('Escape');
            await expect(page.getByRole('button',{name:'Filters (1)',exact:true})).toBeFocused();
        }
    }
    expect(errors).toEqual([]);
});

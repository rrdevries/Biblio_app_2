import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

let source = await readFile(new URL("../../assets/js/settings.js", import.meta.url), "utf8");
for (const [specifier, path] of [["biblio-ui/api","api.js"],["biblio-ui/ui-shell","ui-shell.js"],["./settings-state.js","settings-state.js"]]) {
    source = source.replace(JSON.stringify(specifier),JSON.stringify(new URL(`../../assets/js/${path}`,import.meta.url).href));
}
const {createSettingsApp} = await import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);
class Element {
    constructor(tag) {this.tagName=tag.toUpperCase();this.children=[];this.attributes=new Map();this.textContent="";}
    append(...items) {this.children.push(...items);}
    replaceChildren(...items) {this.children=[...items];}
    setAttribute(k,v) {this.attributes.set(k,v);}
    addEventListener() {}
    contains() {return false;}
}
const descendants = root => root.children.flatMap(child=>[child,...descendants(child)]);
const state = effective => ({value:null,version:0,effective,source:"biblio",default_source:"biblio",default_effective:"grid"});
function harness(mode, fail=false) {
    const calls=[];const contexts=[];const host=new Element("main");
    const root={dataset:{settingsMode:mode,accountState:"authenticated",platformUrl:"https://example.test/mijn-biblio/"}};
    const app=createSettingsApp(root,{documentImpl:{createElement:tag=>new Element(tag)},
        locationImpl:{href:"https://example.test/instellingen/?library_id=exact"},
        shellFactory:()=>({contentRoot:host,setLibraryContext:context=>contexts.push(context),destroy(){}}),
        api:{get:async path=>{
            calls.push(path);if(fail) throw Object.assign(new Error("forbidden"),{status:404});
            return {library:{library_id:"exact",name:"Mijn kast"},capabilities:{manage_defaults:true},
                preferences:{catalog_view:state("grid"),catalog_archive_visible:state(false)},defaults:{catalog_view:state("grid")}};
        }}});
    return {app,host,calls,contexts};
}
test("personal preferences never load shared management, even for an Owner",async()=>{
    const {app,host,calls}=harness("preferences");await app.load();
    assert.deepEqual(calls,["libraries/exact/preferences"]);
    assert.equal(descendants(host).filter(node=>node.tagName==="FIELDSET").length,2);
    assert.equal(descendants(host).find(node=>node.tagName==="H1").textContent,"Mijn voorkeuren");
    assert.ok(!descendants(host).some(node=>node.textContent==="Bibliotheekbeheer"));
});
test("Library management never loads private preferences",async()=>{
    const {app,host,calls,contexts}=harness("defaults");await app.load();
    assert.deepEqual(calls,["libraries/exact/defaults"]);
    assert.equal(descendants(host).filter(node=>node.tagName==="FIELDSET").length,1);
    assert.equal(descendants(host).find(node=>node.tagName==="H1").textContent,"Bibliotheekinstellingen");
    assert.equal(contexts.at(-1).capabilities.manage_defaults,true);
});
test("unauthorized direct management route renders no settings or authorized context",async()=>{
    const {app,host,contexts}=harness("defaults",true);await app.load();
    assert.equal(descendants(host).filter(node=>node.tagName==="FIELDSET").length,0);
    assert.equal(contexts.at(-1),null);
});

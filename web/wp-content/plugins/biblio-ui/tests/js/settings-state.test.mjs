import assert from "node:assert/strict";
import test from "node:test";
import { createSettingControl, createCatalogPresentationSession } from "../../assets/js/settings-state.js";
const state = (value = null, version = 0, effective = "grid", source = "biblio") => ({ value, version, effective, source });
test("one pending write per control; lost successful response is reconciled without duplicate", async () => {
    let saved = state(); let writes = 0; let resolve;
    const control = createSettingControl({ initial: saved, read: async () => saved,
        write: async (value) => { writes++; await new Promise(r => {resolve = r;}); saved = state(value, 1, value, "personal"); throw new Error("lost response"); } });
    const pending = control.choose("list");
    await control.choose("grid");
    assert.equal(writes, 1); assert.equal(control.snapshot().confirmed.value, null);
    resolve(); await pending; await control.retry();
    assert.equal(writes, 1); assert.equal(control.snapshot().status, "saved");
    assert.equal(control.snapshot().confirmed.value, "list");
});
test("read-back failure sends nothing; concurrent reset/new choice is never overwritten", async () => {
    let reads = 0; let writes = 0;
    const control = createSettingControl({ initial: state("grid", 1),
        write: async () => { writes++; throw new Error("offline"); },
        read: async () => { if (++reads === 1) throw new Error("offline"); return state("list", 3, "list", "personal"); } });
    await control.choose(null); await control.retry(); assert.equal(writes, 1);
    await control.retry(); assert.equal(control.snapshot().status, "conflict"); assert.equal(writes, 1);
    assert.equal(control.snapshot().confirmed.version, 3);
});
test("lost authorization blocks retries; explicit conflict requires refreshing and a new choice", async () => {
    let writes = 0;
    const blocked = createSettingControl({ initial: state(), read: async () => state(), write: async () => {writes++; throw {status: 403};} });
    await blocked.choose("list"); await blocked.retry(); await blocked.choose("grid"); assert.equal(writes, 1);
    const conflict = createSettingControl({ initial: state(), read: async () => state("list", 1, "list", "personal"), write: async () => {throw {status:409};} });
    await conflict.choose("grid"); await conflict.retry();
    assert.equal(conflict.snapshot().status, "idle"); assert.equal(conflict.snapshot().confirmed.value, "list");
});
test("temporary view is scoped and invalidated by changed preference without touching other filters", () => {
    const map = new Map([["filters", "retain"]]); const storage = {getItem:k=>map.get(k),setItem:(k,v)=>map.set(k,v)};
    const session = (scope, preference) => createCatalogPresentationSession({storage,scope,preference,setting:"view"});
    session("u:a",state()).write("list");
    assert.equal(session("u:a",state()).read(), "list"); assert.equal(session("u:b",state()).read(), "grid");
    assert.equal(session("u:a",state(null, 2)).read(), "grid"); assert.equal(map.get("filters"), "retain");
    assert.equal(session("u:a",state(null, 2, "list", "library")).read(), "list");
});

test("a different deliberate choice after unknown storage also reads before writing", async () => {
    const events=[]; let count=0;
    const control=createSettingControl({initial:state(),read:async()=>{events.push("read");return state("list",1,"list","personal");},write:async(value,version)=>{events.push([value,version]); if (++count===1) throw new Error("lost"); return state(value,version+1,value,"personal");}});
    await control.choose("list"); await control.choose("grid");
    assert.deepEqual(events,[["list",0],"read",["grid",1]]); assert.equal(control.snapshot().status,"saved");
});

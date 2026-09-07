import assert from "node:assert/strict";
import test from "node:test";

import { createIsbnScanner } from "../../assets/js/isbn-scanner.js";

test("camera scanner reports unsupported without requesting permission", async () => {
    let requested = false;
    let failed = false;
    const scanner = createIsbnScanner({
        mediaDevices: { async getUserMedia() { requested = true; } },
        BarcodeDetectorImpl: undefined,
    });

    assert.equal(scanner.supported(), false);
    assert.equal(await scanner.start({}, { onFailure() { failed = true; } }), false);
    assert.equal(requested, false);
    assert.equal(failed, true);
});

test("camera scanner detects a valid ISBN and always stops the stream", async () => {
    const track = { stopped: false, stop() { this.stopped = true; } };
    const stream = { getTracks() { return [track]; } };
    const timers = [];
    class Detector {
        async detect() {
            return [{ rawValue: "978-0-306-40615-7" }];
        }
    }
    const scanner = createIsbnScanner({
        mediaDevices: { async getUserMedia() { return stream; } },
        BarcodeDetectorImpl: Detector,
        setTimer(callback) {
            timers.push(callback);
            return timers.length;
        },
        clearTimer() {},
    });
    const video = { async play() {}, setAttribute() {} };
    let detected = null;

    assert.equal(await scanner.start(video, { onDetected(value) { detected = value; } }), true);
    await timers.shift()();
    assert.equal(detected, "9780306406157");
    assert.equal(track.stopped, true);
});

test("permission denial becomes a calm fallback and leaves no active stream", async () => {
    let failed = false;
    class Detector {}
    const scanner = createIsbnScanner({
        mediaDevices: { async getUserMedia() { throw new Error("denied"); } },
        BarcodeDetectorImpl: Detector,
    });

    assert.equal(await scanner.start({}, { onFailure() { failed = true; } }), false);
    assert.equal(failed, true);
    scanner.stop();
});

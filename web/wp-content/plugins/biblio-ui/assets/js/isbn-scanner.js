import { normalizeIsbn } from "./add-book-contracts.js";

export function createIsbnScanner({
    mediaDevices = globalThis.navigator?.mediaDevices,
    BarcodeDetectorImpl = globalThis.BarcodeDetector,
    setTimer = globalThis.setTimeout,
    clearTimer = globalThis.clearTimeout,
} = {}) {
    let stream = null;
    let timer = null;
    let active = false;

    function supported() {
        return typeof BarcodeDetectorImpl === "function"
            && typeof mediaDevices?.getUserMedia === "function";
    }

    function stop() {
        active = false;
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
        for (const track of stream?.getTracks?.() ?? []) {
            track.stop?.();
        }
        stream = null;
    }

    async function start(video, { onDetected, onFailure }) {
        stop();
        if (!supported()) {
            onFailure?.();
            return false;
        }

        let detector;
        try {
            detector = new BarcodeDetectorImpl({ formats: ["ean_13"] });
            stream = await mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: "environment" } },
            });
            video.srcObject = stream;
            video.setAttribute?.("playsinline", "");
            video.muted = true;
            await video.play?.();
        } catch {
            stop();
            onFailure?.();
            return false;
        }

        active = true;
        const scan = async () => {
            if (!active) {
                return;
            }
            try {
                const barcodes = await detector.detect(video);
                for (const barcode of barcodes) {
                    const isbn = normalizeIsbn(barcode?.rawValue);
                    if (isbn !== null) {
                        stop();
                        onDetected?.(isbn);
                        return;
                    }
                }
            } catch {
                stop();
                onFailure?.();
                return;
            }
            timer = setTimer(scan, 250);
        };
        timer = setTimer(scan, 0);
        return true;
    }

    return Object.freeze({ supported, start, stop });
}

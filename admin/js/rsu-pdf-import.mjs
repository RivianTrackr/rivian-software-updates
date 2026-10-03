/**
 * PDF import for the release-notes section builder — browser entry.
 *
 * Bundles pdf.js and the structure-extraction logic into a standalone
 * file that rsu-admin.js lazy-loads only when the user picks a PDF.
 * Exposes window.RSUPdfImport.extractText(arrayBuffer, { workerSrc }).
 */

import * as pdfjsLib from 'pdfjs-dist';
import { linesFromTextContent, buildReleaseNotes } from './rsu-pdf-extract.mjs';

// Resolves to { text, meta } where meta is the vehicle / model year /
// software version read off the PDF's title line, or null.
async function extract(data, opts) {
	opts = opts || {};
	if (opts.workerSrc) {
		pdfjsLib.GlobalWorkerOptions.workerSrc = opts.workerSrc;
	}

	var bytes = data instanceof ArrayBuffer ? new Uint8Array(data) : data;
	var doc = await pdfjsLib.getDocument({ data: bytes }).promise;

	try {
		var pages = [];
		for (var p = 1; p <= doc.numPages; p++) {
			var page = await doc.getPage(p);
			var content = await page.getTextContent();
			pages.push(linesFromTextContent(content, p));
		}
		return buildReleaseNotes(pages);
	} finally {
		doc.destroy();
	}
}

async function extractText(data, opts) {
	return (await extract(data, opts)).text;
}

window.RSUPdfImport = { extract: extract, extractText: extractText };

import { baseForm, captureVisibleTab, upload } from './shared.js';

chrome.runtime.onInstalled.addListener(() => {
    chrome.contextMenus.create({ id: 'share-selection', title: 'Share selected text with SSShare', contexts: ['selection'] });
    chrome.contextMenus.create({ id: 'share-image', title: 'Share image with SSShare', contexts: ['image'] });
});

async function notifyLink(tabId, url) {
    // Copy from the page context (service workers have no clipboard).
    await chrome.scripting?.executeScript?.({ target: { tabId }, func: (u) => navigator.clipboard.writeText(u), args: [url] }).catch(() => {});
    await chrome.action.setBadgeText({ text: '✓' });
    setTimeout(() => chrome.action.setBadgeText({ text: '' }), 3000);
}

chrome.contextMenus.onClicked.addListener(async (info, tab) => {
    try {
        const form = await baseForm();
        if (info.menuItemId === 'share-selection') {
            form.append('content', info.selectionText || '');
        } else if (info.menuItemId === 'share-image' && info.srcUrl) {
            form.append('file', await (await fetch(info.srcUrl)).blob(), 'image');
        }
        const data = await upload(form);
        await notifyLink(tab.id, data.share_url);
    } catch (e) {
        await chrome.action.setBadgeText({ text: '!' });
        console.error(e);
    }
});

chrome.commands.onCommand.addListener(async (command, tab) => {
    if (command !== 'capture') return;
    try {
        const form = await baseForm();
        form.append('file', await captureVisibleTab(), 'screenshot.png');
        const data = await upload(form);
        await notifyLink(tab.id, data.share_url);
    } catch (e) {
        await chrome.action.setBadgeText({ text: '!' });
    }
});

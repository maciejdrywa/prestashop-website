const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const code = fs.readFileSync(path.resolve(__dirname, '../../../shop/modules/shopcookieinfo/views/js/notice.js'), 'utf8');

function fixture({ cookie = '', loading = false, blocked = false, protocol = 'https:', present = true } = {}) {
  const handlers = {};
  const writes = [];
  let removed = false;
  const button = { hidden: true, addEventListener: (name, callback) => { handlers[name] = callback; } };
  const notice = {
    dataset: { cookieName: 'shop_cookie_info_v1_1', cookiePath: '/sklep/', cookieMaxAge: '15552000' },
    querySelector: () => button,
    remove: () => { removed = true; },
  };
  const document = {
    readyState: loading ? 'loading' : 'complete',
    getElementById: () => present && !removed ? notice : null,
    addEventListener: (name, callback) => { handlers[name] = callback; },
  };
  Object.defineProperty(document, 'cookie', {
    get: () => { if (blocked) throw new Error('Storage blocked'); return cookie; },
    set: (value) => { if (blocked) throw new Error('Storage blocked'); writes.push(value); },
  });
  vm.runInNewContext(code, { document, window: { location: { protocol } } });
  return { button, handlers, writes, removed: () => removed };
}

test('dismissal persists for 180 days with HTTPS and path scoping', () => {
  const state = fixture();
  assert.equal(state.button.hidden, false);
  assert.equal(state.writes.length, 0);
  state.handlers.click();
  assert.equal(state.removed(), true);
  assert.equal(state.writes[0], 'shop_cookie_info_v1_1=1; Max-Age=15552000; Path=/sklep/; SameSite=Lax; Secure');
});

test('an existing acknowledgement hides cached notice without writing cookies', () => {
  const state = fixture({ cookie: 'other=1; shop_cookie_info_v1_1=1' });
  assert.equal(state.removed(), true);
  assert.equal(state.writes.length, 0);
});

test('another shop acknowledgement does not hide this shop notice', () => {
  assert.equal(fixture({ cookie: 'shop_cookie_info_v1_2=1' }).removed(), false);
});

test('blocked cookie access does not prevent closing this page notice', () => {
  const state = fixture({ blocked: true });
  assert.equal(state.button.hidden, false);
  state.handlers.click();
  assert.equal(state.removed(), true);
});

test('script waits for DOM and safely skips pages with no notice', () => {
  const state = fixture({ loading: true });
  assert.equal(state.button.hidden, true);
  state.handlers.DOMContentLoaded();
  assert.equal(state.button.hidden, false);
  assert.doesNotThrow(() => fixture({ present: false }));
});

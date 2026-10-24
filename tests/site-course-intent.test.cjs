'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { groundingError } = require('../src/chat.cjs');

const course = { id: 44, kind: 'product', url: 'https://gpante.com/product/corel-course/',
  price: { amount: '100', currency: 'IRT' }, attributes: [] };
const page = { id: 45, kind: 'page', url: 'https://gpante.com/coreldraw-training/', excerpt: 'آموزش کورل' };
const evidence = [{ name: 'Knowledge_Search', source: 'site', ok: true, results: [course, page] }];
const state = chatInput => ({ chatInput, messages: [], evidence });
const recommendation = 'دوره کورل، ۱۰۰ تومان\n[دوره](' + course.url + ')';

test('complete and step-by-step describe a course to introduce without requiring a page read', () => {
  for (const question of ['یک دوره آموزش کامل کورل معرفی کن', 'دوره مرحله به مرحله کورل معرفی کن', 'آموزش گام به گام کورل پیشنهاد بده']) {
    assert.equal(groundingError(state(question), recommendation), null, question);
  }
});

test('actual explanations and full text still require a cited page/post content read', () => {
  for (const question of ['مراحل آموزش کورل را توضیح بده', 'متن کامل آموزش کورل را بده', 'آموزش کامل کورل را توضیح بده',
    'دوره معرفی کن و مراحل آموزش کورل را توضیح بده', 'دوره معرفی کن و آموزش گام به گام کورل را شرح بده', 'آموزش کامل کورل']) {
    const output = 'مرحله اول طراحی\n[آموزش](' + page.url + ')';
    assert.equal(groundingError(state(question), output), 'content_read_required', question);
    const read = { name: 'Knowledge_Read', source: 'site', ok: true, results: [{ ...page, content: 'مرحله اول طراحی' }] };
    assert.equal(groundingError({ ...state(question), evidence: [...evidence, read] }, output), null, question);
  }
});

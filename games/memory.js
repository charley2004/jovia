/* Memory Match — flip cards to find pairs */
(function () {
  'use strict';

  var SYMBOLS = ['★', '♫', '✦', '☀', '♥', '⚡', '☂', '♛'];
  var grid = document.getElementById('grid');
  var movesEl = document.getElementById('moves');
  var pairsEl = document.getElementById('pairs');
  var msg = document.getElementById('msg');
  var first = null, lock = false, moves = 0, pairs = 0;

  function shuffle(a) {
    for (var i = a.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function start() {
    grid.innerHTML = '';
    first = null; lock = false; moves = 0; pairs = 0;
    movesEl.textContent = '0'; pairsEl.textContent = '0';
    msg.textContent = 'Flip two cards to find a matching pair.';
    shuffle(SYMBOLS.concat(SYMBOLS)).forEach(function (sym) {
      var card = document.createElement('button');
      card.type = 'button';
      card.className = 'card';
      card.dataset.sym = sym;
      card.setAttribute('aria-label', 'Hidden card');
      card.innerHTML = '<span>' + sym + '</span>';
      grid.appendChild(card);
    });
  }

  grid.addEventListener('click', function (e) {
    var card = e.target.closest('.card');
    if (!card || lock || card.classList.contains('open') || card.classList.contains('done')) return;
    JoviaActivity.ping();
    card.classList.add('open');
    card.setAttribute('aria-label', card.dataset.sym);
    if (!first) { first = card; return; }
    moves++;
    movesEl.textContent = moves;
    if (first.dataset.sym === card.dataset.sym) {
      first.classList.add('done'); card.classList.add('done');
      first = null;
      pairs++;
      pairsEl.textContent = pairs;
      if (pairs === SYMBOLS.length) {
        msg.textContent = 'You found every pair in ' + moves + ' moves! A new board starts in a moment.';
        setTimeout(start, 2200);
      }
    } else {
      lock = true;
      var a = first; first = null;
      setTimeout(function () {
        a.classList.remove('open'); card.classList.remove('open');
        a.setAttribute('aria-label', 'Hidden card'); card.setAttribute('aria-label', 'Hidden card');
        lock = false;
      }, 700);
    }
  });

  document.getElementById('restart').addEventListener('click', function () { JoviaActivity.ping(); start(); });
  start();
})();

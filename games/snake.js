/* Snake — arrow keys or on-screen buttons */
(function () {
  'use strict';

  var canvas = document.getElementById('board');
  var ctx = canvas.getContext('2d');
  var N = 16, S = canvas.width / N;
  var scoreEl = document.getElementById('score');
  var bestEl = document.getElementById('best');
  var msg = document.getElementById('msg');
  var DIRS = { up: [0, -1], down: [0, 1], left: [-1, 0], right: [1, 0] };
  var snake, dir, next, food, score, best = 0, timer;

  function place() {
    do { food = [Math.floor(Math.random() * N), Math.floor(Math.random() * N)]; }
    while (snake.some(function (p) { return p[0] === food[0] && p[1] === food[1]; }));
  }

  function start() {
    snake = [[8, 8], [7, 8], [6, 8]];
    dir = next = 'right';
    score = 0;
    scoreEl.textContent = '0';
    msg.textContent = 'Use the arrow keys or the buttons. Eat the gold dots.';
    place();
    clearInterval(timer);
    timer = setInterval(step, 140);
    draw();
  }

  function turn(d) {
    var opposite = { up: 'down', down: 'up', left: 'right', right: 'left' };
    if (d !== opposite[dir]) next = d;
    JoviaActivity.ping();
  }

  function step() {
    dir = next;
    var head = [snake[0][0] + DIRS[dir][0], snake[0][1] + DIRS[dir][1]];
    var hit = head[0] < 0 || head[1] < 0 || head[0] >= N || head[1] >= N ||
      snake.some(function (p) { return p[0] === head[0] && p[1] === head[1]; });
    if (hit) {
      clearInterval(timer);
      msg.textContent = 'Game over — score ' + score + '. Restarting…';
      setTimeout(start, 1500);
      return;
    }
    snake.unshift(head);
    if (head[0] === food[0] && head[1] === food[1]) {
      score++;
      scoreEl.textContent = score;
      if (score > best) { best = score; bestEl.textContent = best; }
      place();
    } else {
      snake.pop();
    }
    draw();
  }

  function draw() {
    ctx.fillStyle = '#130b2a';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#f5b80f';
    ctx.beginPath();
    ctx.arc(food[0] * S + S / 2, food[1] * S + S / 2, S / 2.6, 0, Math.PI * 2);
    ctx.fill();
    snake.forEach(function (p, i) {
      ctx.fillStyle = i === 0 ? '#a78bfa' : '#7c3aed';
      ctx.fillRect(p[0] * S + 1, p[1] * S + 1, S - 2, S - 2);
    });
  }

  document.addEventListener('keydown', function (e) {
    var map = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right', w: 'up', s: 'down', a: 'left', d: 'right' };
    if (map[e.key]) { e.preventDefault(); turn(map[e.key]); }
  });
  document.querySelector('.pad').addEventListener('click', function (e) {
    var b = e.target.closest('[data-dir]');
    if (b) turn(b.dataset.dir);
  });
  document.getElementById('restart').addEventListener('click', function () { JoviaActivity.ping(); start(); });
  start();
})();

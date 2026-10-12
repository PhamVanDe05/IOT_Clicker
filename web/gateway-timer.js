// Timers for gateway.js. Chrome slows the timers of a hidden tab (to once a second, then once a minute); a worker's
// are not slowed, so the gateway keeps getting its STATE frame every 120 ms while the teacher uses another tab.
'use strict';
const timers = {};
onmessage = ({data}) => {
  clearInterval(timers[data.name]);
  if (data.ms) timers[data.name] = setInterval(() => postMessage(data.name), data.ms);
};

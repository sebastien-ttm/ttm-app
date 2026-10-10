/*
 * Génère public/icons/badge-96.png : le petit pictogramme des notifications push.
 *
 * Android n'utilise que la TRANSPARENCE de cette image (tout pixel opaque devient
 * blanc dans la barre d'état) : une icône carrée pleine donne un carré blanc. On
 * dessine donc un disque blanc dans lequel un « T » est évidé (transparent).
 *
 * Usage : node scripts/generate-notification-badge.mjs
 * Aucun module externe : encodeur PNG minimal (zlib de Node).
 */
import { deflateSync } from 'node:zlib';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SIZE = 96;
const SUPERSAMPLE = 4; // anticrénelage : 4×4 échantillons par pixel

/** Vrai si le point (x, y), en pixels, est dans le disque. */
function inDisc(x, y) {
  const r = SIZE / 2 - 2;
  return (x - SIZE / 2) ** 2 + (y - SIZE / 2) ** 2 <= r * r;
}

/** Vrai si le point est dans le « T » (barre du haut + pied). */
function inLetter(x, y) {
  const bar = x >= 24 && x <= 72 && y >= 25 && y <= 40;
  const stem = x >= 40 && x <= 56 && y >= 25 && y <= 72;
  return bar || stem;
}

const pixels = Buffer.alloc(SIZE * (SIZE * 4 + 1)); // 1 octet de filtre par ligne
for (let py = 0; py < SIZE; py++) {
  const row = py * (SIZE * 4 + 1);
  pixels[row] = 0; // filtre « aucun »
  for (let px = 0; px < SIZE; px++) {
    let covered = 0;
    for (let sy = 0; sy < SUPERSAMPLE; sy++) {
      for (let sx = 0; sx < SUPERSAMPLE; sx++) {
        const x = px + (sx + 0.5) / SUPERSAMPLE;
        const y = py + (sy + 0.5) / SUPERSAMPLE;
        if (inDisc(x, y) && !inLetter(x, y)) covered++;
      }
    }
    const offset = row + 1 + px * 4;
    pixels[offset] = 255;
    pixels[offset + 1] = 255;
    pixels[offset + 2] = 255;
    pixels[offset + 3] = Math.round((covered / (SUPERSAMPLE * SUPERSAMPLE)) * 255);
  }
}

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});

function crc32(buffer) {
  let c = 0xffffffff;
  for (const byte of buffer) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([length, body, crc]);
}

const header = Buffer.alloc(13);
header.writeUInt32BE(SIZE, 0);
header.writeUInt32BE(SIZE, 4);
header[8] = 8; // 8 bits par canal
header[9] = 6; // RGBA

const png = Buffer.concat([
  Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  chunk('IHDR', header),
  chunk('IDAT', deflateSync(pixels, { level: 9 })),
  chunk('IEND', Buffer.alloc(0)),
]);

const target = resolve(dirname(fileURLToPath(import.meta.url)), '../public/icons/badge-96.png');
mkdirSync(dirname(target), { recursive: true });
writeFileSync(target, png);
console.log(`${target} (${png.length} octets)`);

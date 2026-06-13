import React from 'react';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';

function clamp(v, lo, hi) {
  return Math.max(lo, Math.min(hi, v));
}

// Point where the segment from `from` toward `to` exits the rectangle centred on `from`
// with half-extents (hw, hh). Used to start/end the connector at box/label edges.
function edgePoint(from, to, hw, hh) {
  const dx = to.x - from.x;
  const dy = to.y - from.y;
  if (dx === 0 && dy === 0) return { x: from.x, y: from.y };
  const tx = dx !== 0 ? hw / Math.abs(dx) : Infinity;
  const ty = dy !== 0 ? hh / Math.abs(dy) : Infinity;
  const t = Math.min(tx, ty);
  return { x: from.x + dx * t, y: from.y + dy * t };
}

// A callout drawn in VIEWPORT pixel space. The caller projects image-fractions → screen, so
// labels and borders stay crisp at any zoom. The label is placed on the requested side but
// then CLAMPED fully on-screen, with a connector line back to the box — so a label near an
// edge (e.g. a control on the far right) can never run off-frame.
export function Callout({ rect, label, side = 'up', enter, viewport }) {
  const pad = 10;
  const box = {
    left: rect.left - pad,
    top: rect.top - pad,
    width: rect.width + pad * 2,
    height: rect.height + pad * 2,
  };
  const bc = { x: box.left + box.width / 2, y: box.top + box.height / 2 };

  const margin = 30;
  const gap = 26;
  const maxW = 420;
  const charW = 15.5;
  const lineH = 36;
  const padX = 20;
  const padY = 12;

  // Estimate the label box so we can place + clamp it deterministically (no DOM measure).
  const textW = label.length * charW;
  const lw = Math.min(Math.max(textW + padX * 2, 120), maxW);
  const lines = Math.max(1, Math.ceil(textW / (maxW - padX * 2)));
  const lh = lines * lineH + padY * 2;

  // Desired label centre by side, then clamp fully inside the viewport.
  let lc;
  if (side === 'down') lc = { x: bc.x, y: box.top + box.height + gap + lh / 2 };
  else if (side === 'left') lc = { x: box.left - gap - lw / 2, y: bc.y };
  else if (side === 'right') lc = { x: box.left + box.width + gap + lw / 2, y: bc.y };
  else lc = { x: bc.x, y: box.top - gap - lh / 2 };
  lc.x = clamp(lc.x, margin + lw / 2, viewport.width - margin - lw / 2);
  lc.y = clamp(lc.y, margin + lh / 2, viewport.height - margin - lh / 2);

  const labelLeft = lc.x - lw / 2;
  const labelTop = lc.y - lh / 2;

  // Connector from the box edge to the label edge (both facing each other).
  const a = edgePoint(bc, lc, box.width / 2, box.height / 2);
  const b = edgePoint(lc, bc, lw / 2, lh / 2);

  const labelOpacity = Math.max(0, (enter - 0.25) / 0.75);
  const dim = 0.5 * enter;
  const glow = 0.35 + 0.25 * Math.sin(enter * Math.PI);

  return (
    <>
      {/* Spotlight + bright animated border around the region */}
      <div
        style={{
          position: 'absolute',
          left: box.left,
          top: box.top,
          width: box.width,
          height: box.height,
          borderRadius: 12,
          border: `4px solid ${COLORS.highlight}`,
          boxShadow: `0 0 0 9999px rgba(11,18,9,${dim}), 0 0 ${28 * glow}px ${6 * glow}px rgba(255,138,30,${0.6 * enter})`,
          opacity: enter,
        }}
      />
      {/* Connector line from box → label (drawn in viewport space) */}
      <svg
        width={viewport.width}
        height={viewport.height}
        style={{ position: 'absolute', left: 0, top: 0, pointerEvents: 'none', opacity: labelOpacity }}
      >
        <line x1={a.x} y1={a.y} x2={b.x} y2={b.y} stroke={COLORS.highlight} strokeWidth={3} />
        <circle cx={a.x} cy={a.y} r={5} fill={COLORS.highlight} />
      </svg>
      {/* Label pill, clamped fully on-screen */}
      <div
        style={{
          position: 'absolute',
          left: labelLeft,
          top: labelTop,
          width: lw,
          boxSizing: 'border-box',
          background: COLORS.highlight,
          color: '#231300',
          fontFamily: SANS,
          fontWeight: 800,
          fontSize: 28,
          lineHeight: 1.16,
          padding: `${padY}px ${padX}px`,
          borderRadius: 12,
          boxShadow: '0 10px 28px rgba(0,0,0,0.34)',
          textAlign: 'center',
          opacity: labelOpacity,
        }}
      >
        {label}
      </div>
    </>
  );
}

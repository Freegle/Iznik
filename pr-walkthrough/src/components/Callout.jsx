import React from 'react';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';

// A callout drawn in VIEWPORT pixel space (the projection from image-fraction → screen
// is done by the caller, so labels and borders stay crisp at any zoom).
//
//   rect    : { left, top, width, height } in px within the viewport
//   label   : the text tag
//   side    : where the label pill sits relative to the box (up|down|left|right)
//   enter   : 0..1 entrance progress (box draws, then label + spotlight fade in)
//   viewport: { width, height } for keeping the label on-screen
export function Callout({ rect, label, side = 'up', enter, viewport }) {
  const pad = 10;
  const box = {
    left: rect.left - pad,
    top: rect.top - pad,
    width: rect.width + pad * 2,
    height: rect.height + pad * 2,
  };
  const cx = box.left + box.width / 2;
  const cy = box.top + box.height / 2;
  const gap = 26;

  // Auto-flip the label to the side with room, so it never runs off-screen.
  let s = side;
  if (s === 'up' && box.top < 150) s = 'down';
  else if (s === 'down' && box.top + box.height > viewport.height - 150) s = 'up';
  else if (s === 'left' && box.left < 320) s = 'right';
  else if (s === 'right' && box.left + box.width > viewport.width - 320) s = 'left';

  // Anchor point on the box edge + the label's transform so it sits fully outside.
  let ax;
  let ay;
  let translate;
  let connector;
  if (s === 'down') {
    ax = cx; ay = box.top + box.height + gap; translate = 'translate(-50%, 0)';
    connector = { left: cx - 1, top: box.top + box.height, width: 2, height: gap };
  } else if (s === 'left') {
    ax = box.left - gap; ay = cy; translate = 'translate(-100%, -50%)';
    connector = { left: box.left - gap, top: cy - 1, width: gap, height: 2 };
  } else if (s === 'right') {
    ax = box.left + box.width + gap; ay = cy; translate = 'translate(0, -50%)';
    connector = { left: box.left + box.width, top: cy - 1, width: gap, height: 2 };
  } else {
    ax = cx; ay = box.top - gap; translate = 'translate(-50%, -100%)';
    connector = { left: cx - 1, top: box.top - gap, width: 2, height: gap };
  }

  const dim = 0.5 * enter;
  const glow = 0.35 + 0.25 * Math.sin(enter * Math.PI);
  const labelOpacity = Math.max(0, (enter - 0.25) / 0.75);

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
      {/* Connector from box edge to label */}
      <div style={{ position: 'absolute', ...connector, background: COLORS.highlight, opacity: labelOpacity }} />
      {/* Label pill, anchored just outside the box on the chosen side */}
      <div
        style={{
          position: 'absolute',
          left: ax,
          top: ay,
          transform: translate,
          maxWidth: 440,
          width: 'max-content',
          background: COLORS.highlight,
          color: '#231300',
          fontFamily: SANS,
          fontWeight: 800,
          fontSize: 28,
          lineHeight: 1.16,
          padding: '12px 20px',
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

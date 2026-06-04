import React from 'react';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';

// Small chapter label that sits at the top of every scene, giving the walkthrough a
// table-of-contents feel.
export function Eyebrow({ children }) {
  return (
    <div
      style={{
        position: 'absolute',
        top: 50,
        left: '50%',
        transform: 'translateX(-50%)',
        background: COLORS.white,
        border: `2px solid ${COLORS.paperEdge}`,
        color: COLORS.greenDeep,
        fontFamily: SANS,
        fontWeight: 700,
        fontSize: 22,
        letterSpacing: 1.5,
        textTransform: 'uppercase',
        padding: '9px 22px',
        borderRadius: 999,
        boxShadow: '0 4px 14px rgba(0,0,0,0.06)',
      }}
    >
      {children}
    </div>
  );
}

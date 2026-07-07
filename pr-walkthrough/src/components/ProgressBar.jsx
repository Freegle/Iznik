import React from 'react';
import { useCurrentFrame, useVideoConfig, interpolate } from 'remotion';
import { COLORS } from '../theme.js';
import { SANS } from '../fonts.js';

// Persistent thin progress bar along the bottom, plus a scene counter top-right, so the
// viewer always knows how far through the walkthrough they are.
export function ProgressBar({ boundaries, sceneCount }) {
  const frame = useCurrentFrame();
  const { durationInFrames } = useVideoConfig();
  const progress = interpolate(frame, [0, durationInFrames - 1], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Approximate current scene from the cumulative boundary frames.
  let sceneIndex = 0;
  for (let i = 0; i < boundaries.length; i += 1) {
    if (frame >= boundaries[i]) sceneIndex = i;
  }

  return (
    <>
      <div
        style={{
          position: 'absolute',
          top: 48,
          right: 56,
          fontFamily: SANS,
          fontSize: 20,
          fontWeight: 700,
          color: COLORS.inkSoft,
          letterSpacing: 1,
        }}
      >
        {String(sceneIndex + 1).padStart(2, '0')}
        <span style={{ opacity: 0.5 }}> / {String(sceneCount).padStart(2, '0')}</span>
      </div>
      <div
        style={{
          position: 'absolute',
          bottom: 0,
          left: 0,
          width: '100%',
          height: 6,
          background: COLORS.paperEdge,
        }}
      >
        <div
          style={{
            width: `${progress * 100}%`,
            height: '100%',
            background: `linear-gradient(90deg, ${COLORS.green}, ${COLORS.greenDeep})`,
          }}
        />
      </div>
    </>
  );
}

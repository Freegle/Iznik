import React from 'react';
import { interpolate, useCurrentFrame } from 'remotion';

interface SubtitleProps {
  text: string;
  startFrame: number;
  durationFrames: number;
}

export const Subtitle: React.FC<SubtitleProps> = ({
  text,
  startFrame,
  durationFrames,
}) => {
  const frame = useCurrentFrame();
  const relFrame = frame - startFrame;

  if (relFrame < 0 || relFrame > durationFrames) return null;

  const fadeIn = interpolate(relFrame, [0, 10], [0, 1], {
    extrapolateRight: 'clamp',
  });
  const fadeOut = interpolate(
    relFrame,
    [durationFrames - 10, durationFrames],
    [1, 0],
    { extrapolateLeft: 'clamp' }
  );

  return (
    <div
      style={{
        position: 'absolute',
        bottom: 60,
        left: 0,
        right: 0,
        textAlign: 'center',
        opacity: Math.min(fadeIn, fadeOut),
        zIndex: 100,
      }}
    >
      <div
        style={{
          display: 'inline-block',
          background: 'rgba(0, 0, 0, 0.75)',
          color: '#fff',
          padding: '10px 24px',
          borderRadius: 8,
          fontSize: 22,
          fontFamily: "'Segoe UI', sans-serif",
          fontWeight: 500,
          maxWidth: '80%',
          lineHeight: 1.4,
        }}
      >
        {text}
      </div>
    </div>
  );
};

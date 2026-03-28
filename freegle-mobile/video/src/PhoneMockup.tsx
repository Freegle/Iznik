import React from 'react';

interface PhoneMockupProps {
  children: React.ReactNode;
  scale?: number;
}

export const PhoneMockup: React.FC<PhoneMockupProps> = ({
  children,
  scale = 1,
}) => {
  const bezelWidth = 10;
  const phoneWidth = 280;
  const phoneHeight = 590;

  return (
    <div
      style={{
        width: phoneWidth * scale,
        height: phoneHeight * scale,
        borderRadius: 36 * scale,
        background: '#1a1a2e',
        border: '2px solid #2a2a4a',
        padding: `${bezelWidth * scale}px`,
        position: 'relative',
        boxShadow: '0 20px 60px rgba(0,0,0,0.3)',
        overflow: 'hidden',
      }}
    >
      {/* Notch */}
      <div
        style={{
          width: 80 * scale,
          height: 20 * scale,
          background: '#1a1a2e',
          borderRadius: `0 0 ${12 * scale}px ${12 * scale}px`,
          position: 'absolute',
          top: 0,
          left: '50%',
          transform: 'translateX(-50%)',
          zIndex: 10,
        }}
      />
      {/* Screen */}
      <div
        style={{
          width: (phoneWidth - bezelWidth * 2) * scale,
          height: (phoneHeight - bezelWidth * 2) * scale,
          borderRadius: 24 * scale,
          overflow: 'hidden',
          background: '#fff',
          marginTop: 8 * scale,
        }}
      >
        {children}
      </div>
    </div>
  );
};

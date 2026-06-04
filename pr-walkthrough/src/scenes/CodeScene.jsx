import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { Highlight, themes } from 'prism-react-renderer';
import { COLORS } from '../theme.js';
import { SANS, MONO } from '../fonts.js';
import { Eyebrow } from '../components/Eyebrow.jsx';
import { Caption } from '../components/Caption.jsx';

// prism-react-renderer's bundled Prism lacks a few grammars; alias to the closest one.
const LANG_ALIAS = { php: 'clike', vue: 'markup', html: 'markup', ts: 'typescript', sh: 'bash' };

export function CodeScene({ scene }) {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const lang = LANG_ALIAS[scene.language] || scene.language || 'javascript';
  const highlight = new Set(scene.highlight || []);

  const enter = spring({ frame, fps, config: { damping: 200 }, durationInFrames: 18 });
  // Highlighted lines glow in a touch after the block appears.
  const hiGlow = spring({ frame: frame - 16, fps, config: { damping: 200 }, durationInFrames: 22 });

  return (
    <AbsoluteFill style={{ background: `radial-gradient(120% 120% at 50% 0%, ${COLORS.paper}, ${COLORS.paperEdge})`, fontFamily: SANS }}>
      {scene.chapter && <Eyebrow>{scene.chapter}</Eyebrow>}

      <div
        style={{
          position: 'absolute',
          left: 130,
          right: 130,
          top: 150,
          bottom: scene.caption ? 220 : 90,
          borderRadius: 18,
          overflow: 'hidden',
          background: COLORS.codeBg,
          boxShadow: '0 30px 80px rgba(20,40,12,0.28)',
          opacity: enter,
          transform: `translateY(${interpolate(enter, [0, 1], [26, 0])}px)`,
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        {/* file tab */}
        <div style={{ height: 58, background: COLORS.codeBar, display: 'flex', alignItems: 'center', paddingLeft: 26, gap: 16 }}>
          <span style={{ width: 13, height: 13, borderRadius: 99, background: '#ff5f57' }} />
          <span style={{ width: 13, height: 13, borderRadius: 99, background: '#febc2e' }} />
          <span style={{ width: 13, height: 13, borderRadius: 99, background: '#28c840' }} />
          <div style={{ marginLeft: 16, color: '#aeb9c7', fontFamily: MONO, fontSize: 24, fontWeight: 600 }}>
            {scene.file || ''}
          </div>
        </div>

        <div style={{ flex: 1, padding: '24px 30px', overflow: 'hidden' }}>
          <Highlight code={scene.code.replace(/\n$/, '')} language={lang} theme={themes.vsDark}>
            {({ style, tokens, getLineProps, getTokenProps }) => (
              <pre style={{ ...style, background: 'transparent', margin: 0, fontFamily: MONO, fontSize: scene.fontSize || 30, lineHeight: 1.5 }}>
                {tokens.map((line, i) => {
                  const isHi = highlight.has(i + 1);
                  const lp = getLineProps({ line });
                  return (
                    <div
                      key={i}
                      {...lp}
                      style={{
                        ...lp.style,
                        display: 'flex',
                        padding: '0 12px',
                        borderRadius: 6,
                        background: isHi ? `rgba(108,174,62,${0.22 * hiGlow})` : 'transparent',
                        borderLeft: isHi ? `4px solid rgba(255,138,30,${hiGlow})` : '4px solid transparent',
                        opacity: isHi ? 1 : 0.62 + 0.38 * (1 - (scene.highlight ? 1 : 0)),
                      }}
                    >
                      <span style={{ width: 44, flex: '0 0 auto', color: COLORS.codeGutter, userSelect: 'none', textAlign: 'right', paddingRight: 18 }}>{i + 1}</span>
                      <span style={{ whiteSpace: 'pre-wrap' }}>
                        {line.map((token, key) => {
                          const tp = getTokenProps({ token });
                          return <span key={key} {...tp} />;
                        })}
                      </span>
                    </div>
                  );
                })}
              </pre>
            )}
          </Highlight>
        </div>
      </div>

      {scene.caption && <Caption>{scene.caption}</Caption>}
    </AbsoluteFill>
  );
}

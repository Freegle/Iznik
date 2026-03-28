import React from 'react';
import {
  AbsoluteFill,
  Audio,
  Img,
  Sequence,
  interpolate,
  useCurrentFrame,
  staticFile,
  spring,
  useVideoConfig,
} from 'remotion';
import { PhoneMockup } from './PhoneMockup';
import { Subtitle } from './Subtitle';

const FPS = 30;

type TransitionType = 'fade' | 'slide-left' | 'slide-right' | 'slide-up' | 'scroll-down';

interface Scene {
  subtitle: string;
  img?: string;
  durationSec: number;
  transition?: TransitionType;
  scrollAmount?: number; // pixels to scroll during this scene
}

const scenes: Scene[] = [
  // Title card
  { subtitle: '', durationSec: 3 },
  // Onboarding
  {
    subtitle: 'Welcome to a new way to Freegle',
    img: '01-onboarding-welcome.png',
    durationSec: 3,
    transition: 'fade',
  },
  {
    subtitle: 'Three simple steps — see, reply, or offer',
    img: '02-onboarding-how.png',
    durationSec: 3,
    transition: 'slide-left',
  },
  {
    subtitle: 'Join your local community',
    img: '03-onboarding-community.png',
    durationSec: 3,
    transition: 'slide-left',
  },
  // Location
  {
    subtitle: 'Enter your postcode to find free stuff nearby',
    img: '04-location.png',
    durationSec: 3,
    transition: 'fade',
  },
  // Feed
  {
    subtitle: 'A community feed — people sharing near you',
    img: '05-feed.png',
    durationSec: 4,
    transition: 'fade',
  },
  {
    subtitle: 'Scroll through offers, wanteds, and community posts',
    img: '06-feed-scroll.png',
    durationSec: 3,
    transition: 'scroll-down',
    scrollAmount: 300,
  },
  // Detail
  {
    subtitle: 'Tap any post for full details and photos',
    img: '07-detail.png',
    durationSec: 3,
    transition: 'slide-up',
  },
  // Reply
  {
    subtitle: 'Reply privately — just between you and the poster',
    img: '09-reply.png',
    durationSec: 3.5,
    transition: 'slide-right',
  },
  // Settings
  {
    subtitle: 'Manage notifications, location, and more',
    img: '08-settings.png',
    durationSec: 3,
    transition: 'slide-right',
  },
  // Closing card
  { subtitle: '', durationSec: 4 },
];

const totalFrames = scenes.reduce((sum, s) => sum + s.durationSec * FPS, 0);
export const DURATION_IN_FRAMES = totalFrames;

export const DemoVideo: React.FC = () => {
  let frameOffset = 0;
  const sceneData = scenes.map((scene) => {
    const start = frameOffset;
    const dur = scene.durationSec * FPS;
    frameOffset += dur;
    return { ...scene, startFrame: start, durationFrames: dur };
  });

  const bgGradient = 'linear-gradient(135deg, #1d6607 0%, #338808 50%, #4caf50 100%)';

  return (
    <AbsoluteFill style={{ background: bgGradient }}>
      <Audio src={staticFile('bg-music.mp3')} volume={0.7} />

      {/* Title card */}
      <Sequence from={0} durationInFrames={sceneData[0].durationFrames}>
        <TitleCard />
      </Sequence>

      {/* Phone mockup scenes */}
      {sceneData.slice(1, -1).map((scene, i) => {
        if (!scene.img) return null;
        return (
          <Sequence key={i} from={scene.startFrame} durationInFrames={scene.durationFrames}>
            <AbsoluteFill style={{ display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <PhoneScene
                img={scene.img}
                transition={scene.transition || 'fade'}
                scrollAmount={scene.scrollAmount}
                durationFrames={scene.durationFrames}
              />
            </AbsoluteFill>
          </Sequence>
        );
      })}

      {/* Closing card */}
      <Sequence from={sceneData[sceneData.length - 1].startFrame} durationInFrames={sceneData[sceneData.length - 1].durationFrames}>
        <ClosingCard />
      </Sequence>

      {/* Subtitles */}
      {sceneData.map((scene, i) =>
        scene.subtitle ? (
          <Subtitle key={i} text={scene.subtitle} startFrame={scene.startFrame} durationFrames={scene.durationFrames} />
        ) : null
      )}
    </AbsoluteFill>
  );
};

const TitleCard: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const scale = spring({ frame, fps, config: { damping: 80 } });
  const opacity = interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', opacity }}>
      <div style={{ transform: `scale(${scale})` }}>
        <Img src={staticFile('screenshots/01-onboarding-welcome.png')} style={{ width: 80, height: 80, borderRadius: 16, marginBottom: 20 }} />
      </div>
      <div style={{ fontSize: 48, fontWeight: 800, color: 'white', fontFamily: "'Segoe UI', sans-serif", textShadow: '0 2px 8px rgba(0,0,0,0.3)' }}>
        Freegle Mobile
      </div>
      <div style={{ fontSize: 22, color: 'rgba(255,255,255,0.8)', marginTop: 8, fontFamily: "'Segoe UI', sans-serif" }}>
        A new way to give and get
      </div>
    </AbsoluteFill>
  );
};

const ClosingCard: React.FC = () => {
  const frame = useCurrentFrame();
  const opacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', opacity }}>
      <div style={{ fontSize: 36, fontWeight: 700, color: 'white', fontFamily: "'Segoe UI', sans-serif", textAlign: 'center', lineHeight: 1.4 }}>
        Don't throw it away
      </div>
      <div style={{ fontSize: 36, fontWeight: 700, color: '#c8e6c9', fontFamily: "'Segoe UI', sans-serif" }}>
        Give it away
      </div>
      <div style={{ fontSize: 20, color: 'rgba(255,255,255,0.7)', marginTop: 24, fontFamily: "'Segoe UI', sans-serif" }}>
        ilovefreegle.org
      </div>
    </AbsoluteFill>
  );
};

const PhoneScene: React.FC<{
  img: string;
  transition: TransitionType;
  scrollAmount?: number;
  durationFrames: number;
}> = ({ img, transition, scrollAmount = 0, durationFrames }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Entry animation based on transition type
  let translateX = 0;
  let translateY = 0;
  let opacity = 1;

  const TRANSITION_FRAMES = 12;

  switch (transition) {
    case 'fade':
      opacity = interpolate(frame, [0, TRANSITION_FRAMES], [0, 1], { extrapolateRight: 'clamp' });
      break;
    case 'slide-left':
      translateX = interpolate(frame, [0, TRANSITION_FRAMES], [80, 0], { extrapolateRight: 'clamp' });
      opacity = interpolate(frame, [0, 8], [0, 1], { extrapolateRight: 'clamp' });
      break;
    case 'slide-right':
      translateX = interpolate(frame, [0, TRANSITION_FRAMES], [280, 0], { extrapolateRight: 'clamp' });
      opacity = interpolate(frame, [0, 6], [0.3, 1], { extrapolateRight: 'clamp' });
      break;
    case 'slide-up':
      translateY = interpolate(frame, [0, TRANSITION_FRAMES], [500, 0], { extrapolateRight: 'clamp' });
      opacity = interpolate(frame, [0, 8], [0, 1], { extrapolateRight: 'clamp' });
      break;
    case 'scroll-down':
      opacity = interpolate(frame, [0, 8], [0, 1], { extrapolateRight: 'clamp' });
      break;
  }

  // Scroll animation within the phone screen
  const scrollOffset = scrollAmount > 0
    ? interpolate(frame, [TRANSITION_FRAMES, durationFrames - 10], [0, scrollAmount], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' })
    : 0;

  // Exit fade
  const exitOpacity = interpolate(frame, [durationFrames - 8, durationFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <div style={{
      opacity: Math.min(opacity, exitOpacity),
      transform: `translate(${translateX}px, ${translateY}px)`,
    }}>
      <PhoneMockup scale={1.05}>
        <div style={{
          width: '100%',
          height: '100%',
          overflow: 'hidden',
          position: 'relative',
        }}>
          <Img
            src={staticFile(`screenshots/${img}`)}
            style={{
              width: '100%',
              position: 'absolute',
              top: -scrollOffset,
              left: 0,
            }}
          />
        </div>
      </PhoneMockup>
    </div>
  );
};

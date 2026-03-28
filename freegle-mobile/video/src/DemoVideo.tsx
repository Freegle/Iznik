import React from 'react';
import {
  AbsoluteFill,
  Audio,
  Img,
  Sequence,
  interpolate,
  useCurrentFrame,
  staticFile,
} from 'remotion';
import { PhoneMockup } from './PhoneMockup';
import { Subtitle } from './Subtitle';

const FPS = 30;

const scenes: {
  subtitle: string;
  img?: string;
  durationSec: number;
}[] = [
  // Title card
  { subtitle: '', durationSec: 3 },
  // Onboarding
  {
    subtitle: 'Welcome to a new way to Freegle',
    img: '01-onboarding-welcome.png',
    durationSec: 3.5,
  },
  {
    subtitle: 'Three simple steps — see, reply, or offer',
    img: '02-onboarding-how.png',
    durationSec: 3.5,
  },
  {
    subtitle: 'Join millions keeping things out of landfill',
    img: '03-onboarding-community.png',
    durationSec: 3,
  },
  // Location
  {
    subtitle: 'Enter your postcode to find free stuff nearby',
    img: '04-location.png',
    durationSec: 3,
  },
  // Feed
  {
    subtitle: 'A community feed of offers and wanted posts near you',
    img: '05-feed.png',
    durationSec: 4,
  },
  {
    subtitle: 'Scroll through — each post shows who posted and where',
    img: '06-feed-scroll.png',
    durationSec: 3.5,
  },
  // Detail
  {
    subtitle: 'Tap any post for full details and photos',
    img: '07-detail.png',
    durationSec: 3.5,
  },
  // Reply
  {
    subtitle: 'Reply privately — just between you and the poster',
    img: '09-reply.png',
    durationSec: 3.5,
  },
  // Settings
  {
    subtitle: 'Manage notifications, location, and more',
    img: '08-settings.png',
    durationSec: 3.5,
  },
  // Closing card
  { subtitle: '', durationSec: 4 },
];

const totalFrames = scenes.reduce((sum, s) => sum + s.durationSec * FPS, 0);
export const DURATION_IN_FRAMES = totalFrames;

export const DemoVideo: React.FC = () => {
  const frame = useCurrentFrame();

  let frameOffset = 0;
  const sceneData = scenes.map((scene) => {
    const start = frameOffset;
    const dur = scene.durationSec * FPS;
    frameOffset += dur;
    return { ...scene, startFrame: start, durationFrames: dur };
  });

  // Background gradient
  const bgGradient = 'linear-gradient(135deg, #1d6607 0%, #338808 50%, #4caf50 100%)';

  return (
    <AbsoluteFill style={{ background: bgGradient }}>
      {/* Background music */}
      <Audio src={staticFile('bg-music.mp3')} volume={0.7} />

      {/* Title card */}
      <Sequence from={0} durationInFrames={sceneData[0].durationFrames}>
        <AbsoluteFill
          style={{
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Img
            src={staticFile('screenshots/01-onboarding-welcome.png')}
            style={{ width: 80, height: 80, borderRadius: 16, marginBottom: 20 }}
          />
          <div
            style={{
              fontSize: 48,
              fontWeight: 800,
              color: 'white',
              fontFamily: "'Segoe UI', sans-serif",
              textShadow: '0 2px 8px rgba(0,0,0,0.3)',
            }}
          >
            Freegle Mobile
          </div>
          <div
            style={{
              fontSize: 22,
              color: 'rgba(255,255,255,0.8)',
              marginTop: 8,
              fontFamily: "'Segoe UI', sans-serif",
            }}
          >
            A new way to give and get
          </div>
        </AbsoluteFill>
      </Sequence>

      {/* Phone mockup scenes */}
      {sceneData.slice(1, -1).map((scene, i) => {
        if (!scene.img) return null;
        return (
          <Sequence
            key={i}
            from={scene.startFrame}
            durationInFrames={scene.durationFrames}
          >
            <AbsoluteFill
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <PhoneScene
                img={scene.img}
                startFrame={scene.startFrame}
                durationFrames={scene.durationFrames}
              />
            </AbsoluteFill>
          </Sequence>
        );
      })}

      {/* Closing card */}
      <Sequence
        from={sceneData[sceneData.length - 1].startFrame}
        durationInFrames={sceneData[sceneData.length - 1].durationFrames}
      >
        <AbsoluteFill
          style={{
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <div
            style={{
              fontSize: 36,
              fontWeight: 700,
              color: 'white',
              fontFamily: "'Segoe UI', sans-serif",
              textAlign: 'center',
              lineHeight: 1.4,
            }}
          >
            Don't throw it away
          </div>
          <div
            style={{
              fontSize: 36,
              fontWeight: 700,
              color: '#c8e6c9',
              fontFamily: "'Segoe UI', sans-serif",
              textAlign: 'center',
            }}
          >
            Give it away
          </div>
          <div
            style={{
              fontSize: 20,
              color: 'rgba(255,255,255,0.7)',
              marginTop: 24,
              fontFamily: "'Segoe UI', sans-serif",
            }}
          >
            ilovefreegle.org
          </div>
        </AbsoluteFill>
      </Sequence>

      {/* Subtitles */}
      {sceneData.map((scene, i) =>
        scene.subtitle ? (
          <Subtitle
            key={i}
            text={scene.subtitle}
            startFrame={scene.startFrame}
            durationFrames={scene.durationFrames}
          />
        ) : null
      )}
    </AbsoluteFill>
  );
};

const PhoneScene: React.FC<{
  img: string;
  startFrame: number;
  durationFrames: number;
}> = ({ img }) => {
  // useCurrentFrame() inside a Sequence returns frames relative to the sequence start
  const frame = useCurrentFrame();

  const slideIn = interpolate(frame, [0, 12], [30, 0], {
    extrapolateRight: 'clamp',
  });
  const fadeIn = interpolate(frame, [0, 10], [0, 1], {
    extrapolateRight: 'clamp',
  });

  return (
    <div
      style={{
        opacity: fadeIn,
        transform: `translateY(${slideIn}px)`,
      }}
    >
      <PhoneMockup scale={1.05}>
        <Img
          src={staticFile(`screenshots/${img}`)}
          style={{
            width: '100%',
            height: '100%',
            objectFit: 'cover',
            objectPosition: 'top',
          }}
        />
      </PhoneMockup>
    </div>
  );
};

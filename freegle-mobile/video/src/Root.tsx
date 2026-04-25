import { Composition } from 'remotion';
import { DemoVideo, DURATION_IN_FRAMES } from './DemoVideo';

export const RemotionRoot: React.FC = () => {
  return (
    <Composition
      id="FreegleMobileDemo"
      component={DemoVideo}
      durationInFrames={DURATION_IN_FRAMES}
      fps={30}
      width={1280}
      height={720}
    />
  );
};

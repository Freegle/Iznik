import { Config } from '@remotion/cli/config';

Config.setVideoImageFormat('jpeg');
Config.setCodec('h264');
Config.setOverwriteOutput(true);
// A high CRF keeps these mostly-static walkthroughs small without visible loss.
Config.setCrf(23);

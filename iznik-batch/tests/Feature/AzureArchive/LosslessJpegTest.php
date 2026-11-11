<?php

namespace Tests\Feature\AzureArchive;

use App\Services\AzureArchive\LosslessJpeg;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Runs the real jpegtran and djpeg, which the batch image installs (libjpeg-turbo-progs). The
 * promise is that what is stored decodes to exactly the pixels Azure held.
 */
class LosslessJpegTest extends TestCase
{
    private function gdJpeg(int $quality = 90): string
    {
        $image = imagecreatetruecolor(320, 240);

        for ($x = 0; $x < 320; $x += 8) {
            for ($y = 0; $y < 240; $y += 8) {
                imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, imagecolorallocate($image, ($x * 3) % 256, ($y * 5) % 256, ($x + $y) % 256));
            }
        }

        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function decode(string $jpeg): string
    {
        $dir = sys_get_temp_dir() . '/lj-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/a.jpg", $jpeg);
        Process::run(['djpeg', '-ppm', '-outfile', "{$dir}/a.ppm", "{$dir}/a.jpg"])->throw();
        $pixels = (string) file_get_contents("{$dir}/a.ppm");
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        return $pixels;
    }

    public function test_the_tools_are_installed_in_the_batch_image(): void
    {
        $this->assertTrue((new LosslessJpeg())->available(), 'libjpeg-turbo-progs must be in iznik-batch/docker/Dockerfile');
    }

    public function test_a_jpeg_is_stored_smaller_with_identical_pixels(): void
    {
        $original = $this->gdJpeg();

        $result = (new LosslessJpeg())->optimise($original);

        $this->assertTrue($result['optimised']);
        $this->assertLessThan(strlen($original), strlen($result['bytes']));
        $this->assertTrue(LosslessJpeg::isJpeg($result['bytes']));
        $this->assertSame($this->decode($original), $this->decode($result['bytes']));
    }

    public function test_what_is_not_a_jpeg_is_kept_as_it_is(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32);

        $result = (new LosslessJpeg())->optimise($png);

        $this->assertSame($png, $result['bytes']);
        $this->assertFalse($result['optimised']);
    }

    public function test_a_broken_jpeg_is_kept_as_it_is(): void
    {
        $broken = substr($this->gdJpeg(), 0, 40);

        $result = (new LosslessJpeg())->optimise($broken);

        $this->assertSame($broken, $result['bytes']);
        $this->assertFalse($result['optimised']);
    }

    public function test_an_already_optimised_jpeg_is_kept_as_it_is(): void
    {
        $once = (new LosslessJpeg())->optimise($this->gdJpeg())['bytes'];

        $twice = (new LosslessJpeg())->optimise($once);

        $this->assertSame($once, $twice['bytes']);
        $this->assertFalse($twice['optimised']);
    }

    public function test_it_leaves_no_temporary_files(): void
    {
        $before = glob(sys_get_temp_dir() . '/azure-archive-*') ?: [];

        (new LosslessJpeg())->optimise($this->gdJpeg());

        $this->assertSame($before, glob(sys_get_temp_dir() . '/azure-archive-*') ?: []);
    }
}

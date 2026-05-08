package avatar

import (
	"bytes"
	"fmt"
	"image"
	"image/png"
	"math"
	"net/url"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
	"github.com/srwiley/oksvg"
	"github.com/srwiley/rasterx"
)

// hashString replicates the JS int32 bitwise DJB2-style hash used by GeneratedAvatar.client.vue.
// Must match exactly: GeneratedAvatar.client.vue hashString() and avatar-server/server.js hashString().
func hashString(s string) int {
	var h int32
	for _, c := range s {
		h = (h << 5) - h + int32(c)
	}
	if h < 0 {
		return int(-h)
	}
	return int(h)
}

// digitAt returns the decimal digit at position pos (0=ones, 1=tens, 2=hundreds…).
func digitAt(n, pos int) int {
	return (n / int(math.Pow10(pos))) % 10
}

// modSigned returns n%t, negated when the digit of n at pos is even.
// Mirrors the JS helper: o(e,t,r) = { l=e%t; return (r && digit(e,r)%2==0) ? -l : l }
func modSigned(n, t, pos int) int {
	v := n % t
	if digitAt(n, pos)%2 == 0 {
		return -v
	}
	return v
}

// pick returns colors[n%len(colors)].
func pick(n int, colors []string) string {
	return colors[n%len(colors)]
}

var colorPalettes = [][]string{
	{"#2E7D32", "#4CAF50", "#81C784", "#A5D6A7", "#C8E6C9"},
	{"#1565C0", "#42A5F5", "#90CAF9", "#4DB6AC", "#80CBC4"},
	{"#7B1FA2", "#BA68C8", "#E1BEE7", "#F48FB1", "#F8BBD9"},
	{"#E65100", "#FF9800", "#FFB74D", "#FFCC80", "#FFE0B2"},
	{"#00695C", "#26A69A", "#80CBC4", "#4DD0E1", "#80DEEA"},
	{"#5D4037", "#8D6E63", "#BCAAA4", "#A1887F", "#D7CCC8"},
	{"#C62828", "#EF5350", "#FFCDD2", "#FF8A65", "#FFAB91"},
	{"#AD1457", "#EC407A", "#F48FB1", "#CE93D8", "#E1BEE7"},
	{"#1A237E", "#3949AB", "#7986CB", "#9FA8DA", "#C5CAE9"},
	{"#004D40", "#00796B", "#4DB6AC", "#80CBC4", "#B2DFDB"},
}

var allVariants = []string{"pixel", "beam", "bauhaus", "ring", "spots", "tiles"}

func getAvatarParams(name string) (hash int, variant string, colors []string) {
	if name == "" {
		name = "user"
	}
	hash = hashString(name)
	variant = allVariants[hash%len(allVariants)]
	colorIndex := (hash / len(allVariants)) % len(colorPalettes)
	colors = colorPalettes[colorIndex]
	return
}

// luminance returns perceived brightness (0–255) of a hex color.
func luminance(hex string) float64 {
	hex = strings.TrimPrefix(hex, "#")
	r, _ := strconv.ParseInt(hex[0:2], 16, 64)
	g, _ := strconv.ParseInt(hex[2:4], 16, 64)
	b, _ := strconv.ParseInt(hex[4:6], 16, 64)
	return (299*float64(r) + 587*float64(g) + 114*float64(b)) / 1000
}

// pixelPositions lists the (x,y) grid coordinates in the order boring-avatars assigns them.
var pixelPositions = func() [][2]int {
	pos := make([][2]int, 0, 64)
	for _, x := range []int{0, 20, 40, 60, 10, 30, 50, 70} {
		pos = append(pos, [2]int{x, 0})
	}
	for _, x := range []int{0, 20, 40, 60, 10, 30, 50, 70} {
		for y := 10; y <= 70; y += 10 {
			pos = append(pos, [2]int{x, y})
		}
	}
	return pos
}()

func generatePixelSVG(hash int, colors []string, size int) string {
	var sb strings.Builder
	fmt.Fprintf(&sb, `<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">`, size, size)
	for i, p := range pixelPositions {
		c := colors[(hash%(i+1))%len(colors)]
		fmt.Fprintf(&sb, `<rect x="%d" y="%d" width="10" height="10" fill="%s"/>`, p[0], p[1], c)
	}
	sb.WriteString(`</svg>`)
	return sb.String()
}

func generateBeamSVG(hash int, colors []string, size int) string {
	wrapperColor := pick(hash, colors)
	backgroundColor := pick(hash+13, colors)

	faceColor := "#000000"
	if luminance(wrapperColor) < 128 {
		faceColor = "#FFFFFF"
	}

	c1 := modSigned(hash, 10, 1)
	wrapTX := c1
	if c1 < 5 {
		wrapTX = c1 + 4
	}
	c2 := modSigned(hash, 10, 2)
	wrapTY := c2
	if c2 < 5 {
		wrapTY = c2 + 4
	}

	wrapRotate := hash % 360
	wrapScale := 1.0 + float64(hash%3)/10.0
	isMouthOpen := digitAt(hash, 2)%2 == 0
	isCircle := digitAt(hash, 1)%2 == 0
	eyeSpread := hash % 5
	mouthSpread := hash % 3
	faceRotate := modSigned(hash, 10, 3)

	var faceTX, faceTY int
	if wrapTX > 6 {
		faceTX = wrapTX / 2
	} else {
		faceTX = modSigned(hash, 8, 1)
	}
	if wrapTY > 6 {
		faceTY = wrapTY / 2
	} else {
		faceTY = modSigned(hash, 7, 2)
	}

	wrapRx := 6
	if isCircle {
		wrapRx = 36
	}

	var mouth string
	if isMouthOpen {
		mouth = fmt.Sprintf(`<path d="M15 %dc2 1 4 1 6 0" stroke="%s" fill="none" stroke-linecap="round"/>`,
			19+mouthSpread, faceColor)
	} else {
		mouth = fmt.Sprintf(`<path d="M13,%d a1,0.75 0 0,0 10,0" fill="%s"/>`,
			19+mouthSpread, faceColor)
	}

	return fmt.Sprintf(
		`<svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">`+
			`<rect width="36" height="36" fill="%s"/>`+
			`<rect x="0" y="0" width="36" height="36"`+
			` transform="translate(%d %d) rotate(%d 18 18) scale(%g)"`+
			` fill="%s" rx="%d"/>`+
			`<g transform="translate(%d %d) rotate(%d 18 18)">`+
			`%s`+
			`<rect x="%g" y="14" width="1.5" height="2" rx="1" fill="%s"/>`+
			`<rect x="%g" y="14" width="1.5" height="2" rx="1" fill="%s"/>`+
			`</g></svg>`,
		size, size,
		backgroundColor,
		wrapTX, wrapTY, wrapRotate, wrapScale, wrapperColor, wrapRx,
		faceTX, faceTY, faceRotate,
		mouth,
		float64(14-eyeSpread), faceColor,
		float64(20+eyeSpread), faceColor,
	)
}

func generateBauhausSVG(hash int, colors []string, size int) string {
	type elem struct {
		color      string
		translateX int
		translateY int
		rotate     int
		isSquare   bool
	}

	elems := [4]elem{}
	for i := range elems {
		e := hash * (i + 1)
		t := 40 - (i + 17)
		elems[i] = elem{
			color:      pick(hash+i, colors),
			translateX: modSigned(e, t, 1),
			translateY: modSigned(e, t, 2),
			rotate:     e % 360,
			isSquare:   digitAt(hash, 2)%2 == 0,
		}
	}

	rectH := 10
	if elems[1].isSquare {
		rectH = 80
	}

	return fmt.Sprintf(
		`<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">`+
			`<rect width="80" height="80" fill="%s"/>`+
			`<rect x="10" y="30" width="80" height="%d" fill="%s"`+
			` transform="translate(%d %d) rotate(%d 40 40)"/>`+
			`<circle cx="40" cy="40" r="16" fill="%s" transform="translate(%d %d)"/>`+
			`<line x1="0" y1="40" x2="80" y2="40" stroke-width="2" stroke="%s"`+
			` transform="translate(%d %d) rotate(%d 40 40)"/>`+
			`</svg>`,
		size, size,
		elems[0].color,
		rectH, elems[1].color, elems[1].translateX, elems[1].translateY, elems[1].rotate,
		elems[2].color, elems[2].translateX, elems[2].translateY,
		elems[3].color, elems[3].translateX, elems[3].translateY, elems[3].rotate,
	)
}

func generateRingSVG(hash int, colors []string, size int) string {
	c := [5]string{}
	for i := range c {
		c[i] = pick(hash+i, colors)
	}
	f := [9]string{c[0], c[1], c[1], c[2], c[2], c[3], c[3], c[0], c[4]}

	return fmt.Sprintf(
		`<svg viewBox="0 0 90 90" fill="none" xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">`+
			`<path d="M0 0h90v45H0z" fill="%s"/>`+
			`<path d="M0 45h90v45H0z" fill="%s"/>`+
			`<path d="M83 45a38 38 0 00-76 0h76z" fill="%s"/>`+
			`<path d="M83 45a38 38 0 01-76 0h76z" fill="%s"/>`+
			`<path d="M77 45a32 32 0 10-64 0h64z" fill="%s"/>`+
			`<path d="M77 45a32 32 0 11-64 0h64z" fill="%s"/>`+
			`<path d="M71 45a26 26 0 00-52 0h52z" fill="%s"/>`+
			`<path d="M71 45a26 26 0 01-52 0h52z" fill="%s"/>`+
			`<circle cx="45" cy="45" r="23" fill="%s"/>`+
			`</svg>`,
		size, size,
		f[0], f[1], f[2], f[3], f[4], f[5], f[6], f[7], f[8],
	)
}

func generateSpotsSVG(hash int, colors []string, size int) string {
	spots := [5][3]int{
		{25 + (hash % 15), 25 + ((hash >> 2) % 15), 20 + (hash % 10)},
		{70 + ((hash >> 3) % 15), 30 + ((hash >> 5) % 15), 18 + ((hash >> 4) % 12)},
		{30 + ((hash >> 6) % 20), 70 + ((hash >> 7) % 15), 22 + ((hash >> 8) % 10)},
		{75 + ((hash >> 9) % 15), 72 + ((hash >> 10) % 15), 16 + ((hash >> 11) % 10)},
		{50 + ((hash>>12)%20) - 10, 50 + ((hash>>13)%20) - 10, 15 + ((hash >> 14) % 8)},
	}

	var sb strings.Builder
	fmt.Fprintf(&sb, `<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 100 100">`, size, size)
	fmt.Fprintf(&sb, `<rect width="100" height="100" fill="%s"/>`, colors[0])
	for i, s := range spots {
		fmt.Fprintf(&sb, `<circle cx="%d" cy="%d" r="%d" fill="%s"/>`, s[0], s[1], s[2], colors[(i%4)+1])
	}
	sb.WriteString(`</svg>`)
	return sb.String()
}

func generateTilesSVG(hash int, colors []string, size int) string {
	tiles := [4][4]int{
		{0, 0, 45 + (hash % 15), 45 + ((hash >> 2) % 15)},
		{50 + ((hash >> 3) % 10), 5, 40 + ((hash >> 4) % 15), 35 + ((hash >> 5) % 15)},
		{5, 55 + ((hash >> 6) % 10), 35 + ((hash >> 7) % 15), 38 + ((hash >> 8) % 12)},
		{45 + ((hash >> 9) % 10), 45 + ((hash >> 10) % 10), 50, 50},
	}

	var sb strings.Builder
	fmt.Fprintf(&sb, `<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 100 100">`, size, size)
	fmt.Fprintf(&sb, `<rect width="100" height="100" fill="%s"/>`, colors[0])
	for i, t := range tiles {
		fmt.Fprintf(&sb, `<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>`, t[0], t[1], t[2], t[3], colors[(i%4)+1])
	}
	sb.WriteString(`</svg>`)
	return sb.String()
}

func generateSVG(name string, size int) string {
	hash, variant, colors := getAvatarParams(name)
	switch variant {
	case "pixel":
		return generatePixelSVG(hash, colors, size)
	case "beam":
		return generateBeamSVG(hash, colors, size)
	case "bauhaus":
		return generateBauhausSVG(hash, colors, size)
	case "ring":
		return generateRingSVG(hash, colors, size)
	case "spots":
		return generateSpotsSVG(hash, colors, size)
	case "tiles":
		return generateTilesSVG(hash, colors, size)
	default:
		return generatePixelSVG(hash, colors, size)
	}
}

func svgToPNG(svgData string, size int) ([]byte, error) {
	icon, err := oksvg.ReadIconStream(strings.NewReader(svgData))
	if err != nil {
		return nil, fmt.Errorf("svg parse: %w", err)
	}
	icon.SetTarget(0, 0, float64(size), float64(size))

	rgba := image.NewRGBA(image.Rect(0, 0, size, size))
	scanner := rasterx.NewScannerGV(size, size, rgba, rgba.Bounds())
	raster := rasterx.NewDasher(size, size, scanner)
	icon.Draw(raster, 1.0)

	var buf bytes.Buffer
	if err := png.Encode(&buf, rgba); err != nil {
		return nil, fmt.Errorf("png encode: %w", err)
	}
	return buf.Bytes(), nil
}

// GetAvatar handles GET /api/avatar/:name
// Returns a PNG avatar image deterministically generated from the name,
// using the same algorithm as the frontend GeneratedAvatar.client.vue component.
func GetAvatar(c *fiber.Ctx) error {
	rawName, err := url.PathUnescape(c.Params("name"))
	if err != nil {
		rawName = c.Params("name")
	}
	name := strings.TrimSuffix(rawName, ".png")

	size := 48
	if s := c.QueryInt("size", 0); s > 0 && s <= 256 {
		size = s
	}

	svgData := generateSVG(name, size)
	pngData, err := svgToPNG(svgData, size)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).SendString("avatar generation failed")
	}

	c.Set("Content-Type", "image/png")
	c.Set("Cache-Control", "public, max-age=86400")
	return c.Send(pngData)
}

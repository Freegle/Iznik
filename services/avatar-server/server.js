'use strict'

const express = require('express')
const React = require('react')
const { renderToStaticMarkup } = require('react-dom/server')
const boringAvatars = require('boring-avatars')
const sharp = require('sharp')

// boring-avatars may export default as .default (CJS interop)
const Avatar = boringAvatars.default || boringAvatars

const app = express()

// Must exactly match GeneratedAvatar.client.vue
const boringVariants = ['pixel', 'beam', 'bauhaus', 'ring']
const customVariants = ['spots', 'tiles']
const allVariants = [...boringVariants, ...customVariants]

const colorPalettes = [
  ['#2E7D32', '#4CAF50', '#81C784', '#A5D6A7', '#C8E6C9'],
  ['#1565C0', '#42A5F5', '#90CAF9', '#4DB6AC', '#80CBC4'],
  ['#7B1FA2', '#BA68C8', '#E1BEE7', '#F48FB1', '#F8BBD9'],
  ['#E65100', '#FF9800', '#FFB74D', '#FFCC80', '#FFE0B2'],
  ['#00695C', '#26A69A', '#80CBC4', '#4DD0E1', '#80DEEA'],
  ['#5D4037', '#8D6E63', '#BCAAA4', '#A1887F', '#D7CCC8'],
  ['#C62828', '#EF5350', '#FFCDD2', '#FF8A65', '#FFAB91'],
  ['#AD1457', '#EC407A', '#F48FB1', '#CE93D8', '#E1BEE7'],
  ['#1A237E', '#3949AB', '#7986CB', '#9FA8DA', '#C5CAE9'],
  ['#004D40', '#00796B', '#4DB6AC', '#80CBC4', '#B2DFDB'],
]

function hashString(str) {
  let hash = 0
  for (let i = 0; i < str.length; i++) {
    const char = str.charCodeAt(i)
    hash = (hash << 5) - hash + char
    hash = hash & hash
  }
  return Math.abs(hash)
}

function getAvatarParams(name) {
  const hash = hashString(name || 'user')
  const variant = allVariants[hash % allVariants.length]
  const colorIndex = Math.floor(hash / allVariants.length) % colorPalettes.length
  return { hash, variant, colors: colorPalettes[colorIndex] }
}

function buildCustomSvg(variant, hash, colors, size) {
  if (variant === 'spots') {
    const spots = [
      { x: 25 + (hash % 15),           y: 25 + ((hash >> 2) % 15),   r: 20 + (hash % 10) },
      { x: 70 + ((hash >> 3) % 15),    y: 30 + ((hash >> 5) % 15),   r: 18 + ((hash >> 4) % 12) },
      { x: 30 + ((hash >> 6) % 20),    y: 70 + ((hash >> 7) % 15),   r: 22 + ((hash >> 8) % 10) },
      { x: 75 + ((hash >> 9) % 15),    y: 72 + ((hash >> 10) % 15),  r: 16 + ((hash >> 11) % 10) },
      { x: 50 + ((hash >> 12) % 20) - 10, y: 50 + ((hash >> 13) % 20) - 10, r: 15 + ((hash >> 14) % 8) },
    ]
    const circles = spots.map((s, i) => `<circle cx="${s.x}" cy="${s.y}" r="${s.r}" fill="${colors[(i % 4) + 1]}"/>`).join('')
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 100 100"><rect width="100" height="100" fill="${colors[0]}"/>${circles}</svg>`
  }
  // tiles
  const tiles = [
    { x: 0,                         y: 0,                         w: 45 + (hash % 15),         h: 45 + ((hash >> 2) % 15) },
    { x: 50 + ((hash >> 3) % 10),   y: 5,                         w: 40 + ((hash >> 4) % 15),  h: 35 + ((hash >> 5) % 15) },
    { x: 5,                         y: 55 + ((hash >> 6) % 10),   w: 35 + ((hash >> 7) % 15),  h: 38 + ((hash >> 8) % 12) },
    { x: 45 + ((hash >> 9) % 10),   y: 45 + ((hash >> 10) % 10), w: 50,                        h: 50 },
  ]
  const rects = tiles.map((t, i) => `<rect x="${t.x}" y="${t.y}" width="${t.w}" height="${t.h}" fill="${colors[(i % 4) + 1]}"/>`).join('')
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 100 100"><rect width="100" height="100" fill="${colors[0]}"/>${rects}</svg>`
}

app.get('/:name', async (req, res) => {
  try {
    const name = decodeURIComponent(req.params.name.replace(/\.png$/, ''))
    const size = Math.min(parseInt(req.query.size || '48', 10), 256)
    const { hash, variant, colors } = getAvatarParams(name)

    let svgString
    if (customVariants.includes(variant)) {
      svgString = buildCustomSvg(variant, hash, colors, size)
    } else {
      svgString = renderToStaticMarkup(
        React.createElement(Avatar, { name, size, variant, colors })
      )
      if (!svgString.includes('xmlns')) {
        svgString = svgString.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ')
      }
    }

    const png = await sharp(Buffer.from(svgString))
      .resize(size, size)
      .png()
      .toBuffer()

    res.set('Content-Type', 'image/png')
    res.set('Cache-Control', 'public, max-age=86400')
    res.send(png)
  } catch (err) {
    console.error('Avatar error:', err)
    res.status(500).send('Avatar generation failed')
  }
})

app.get('/health', (_req, res) => res.send('ok'))

const port = process.env.PORT || 3000
app.listen(port, () => console.log(`Avatar server on :${port}`))

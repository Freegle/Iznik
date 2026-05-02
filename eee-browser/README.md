# EEE Classification Browser

A minimal Nuxt 3 app for browsing EEE (Electrical and Electronic Equipment) classification data stored in SQLite.

## Purpose

Local development tool for researchers to browse classification data: view a message's subject, description, and images alongside the verdicts each model (Claude, Gemini) gave — side by side to spot agreement/disagreement.

## Setup

### Prerequisites
- Node.js 18+
- SQLite database at path specified in `.env`

### Installation

```bash
cd /home/edward/FreegleDocker-eee/eee-browser
npm install
```

### Configuration

Copy `.env.example` to `.env` and update paths as needed:

```bash
cp .env.example .env
```

The `.env` file should contain:
```
EEE_SQLITE_PATH=/path/to/classifications.sqlite
TUS_BASE_URL=https://images.ilovefreegle.org/
```

## Development

```bash
npm run dev
```

The app will start on `http://localhost:3000` (or `3001` if 3000 is in use).

## Building for Production

```bash
npm run build
npm run preview
```

## Pages

### `/` - Item Types List
- Table of all item types from `eee_item_types`
- Shows counts of EEE verdicts per model (Claude, Gemini)
- Agreement percentage highlighted by color
- Click row to navigate to item details

### `/item/:name` - Item Type Detail
- Displays up to 10 sample images for the item type
- Side-by-side model comparison for each image:
  - `is_eee` verdict (boolean badge with confidence %)
  - `contains_eee_components` verdict
  - `electrical_components_description` (truncated, readable)
  - Condition, brand, model number, photo quality, value, weight
  - Expandable reasoning/explanation
- Red/amber/green highlighting shows agreement status between models
- Image URLs fetched from TUS (`images.ilovefreegle.org`)

### `/components` - Component Index
- Table of all `eee_component_types` organized by category:
  - Primary EEE (blue header)
  - Supplementary EEE (amber header)
  - Non-Electrical (gray header)
- Shows usage count for each component across classifications

## API Routes

All API routes are server-side and read from SQLite:

### GET `/api/item-types`
Returns all item types with per-model statistics.

**Response:**
```json
{
  "items": [
    {
      "itemName": "Microwave",
      "weeCategory": "Small equipment (<50cm)",
      "agreeRate": 0.95,
      "sampleSize": 10,
      "imagesAnalysed": 10,
      "claudeEeeCount": 9,
      "geminiEeeCount": 9,
      "claudeTotal": 10,
      "geminiTotal": 10
    }
  ]
}
```

### GET `/api/item-types/:name/images`
Returns up to 10 sample images for an item type with per-model classifications.

**Response:**
```json
{
  "itemName": "Microwave",
  "images": [
    {
      "messageid": 119984473,
      "attid": 44157973,
      "externaluid": "freegletusd-...",
      "imageUrl": "https://images.ilovefreegle.org/freegletusd-...",
      "subject": "OFFER: Microwave (Coulby Newham TS8)",
      "textbody": "...",
      "classifications": [
        {
          "model": "claude-sonnet-4-6",
          "isEee": 1,
          "isEeeConfidence": 0.9,
          "isEeeReasoning": "...",
          "containsEeeComponents": 1,
          "electricalComponentsDescription": "...",
          "condition": "Reusable",
          "brand": "Matsui",
          "modelNumber": null,
          "photoQuality": 4,
          "valueBandGbp": "20-100",
          "weightKgMin": 10,
          "weightKgMax": 15
        }
      ]
    }
  ]
}
```

### GET `/api/components`
Returns all components grouped by category with usage counts.

**Response:**
```json
{
  "primary_eee": [
    {
      "id": 655,
      "name": "motor",
      "category": "primary_eee",
      "usageCount": 32
    }
  ],
  "supplementary_eee": [...],
  "non_electrical": [...],
  "unknown": [...]
}
```

## Data Source

SQLite schema expected:

- **eee_item_types**: Item type definitions with summary verdicts
- **eee_item_type_samples**: Sample images for each item type (max 10 per type)
- **eee_classifications**: Per-image classifications from each model
- **eee_component_types**: Recognized EEE component types with categories

## Tech Stack

- **Nuxt 3** - Vue 3 framework for server-side rendering
- **Tailwind CSS** - Utility-first styling
- **better-sqlite3** - Synchronous SQLite driver for Node.js
- **Nitro** - Server framework (included with Nuxt)

## Notes

- No authentication or deployment features; local dev only
- All API routes use synchronous SQLite reads for simplicity
- Images are external (TUS); app gracefully fallbacks to placeholder on 404
- Reasoning text is expandable (click `<details>`) to avoid clutter
- Component usage counts are approximate (substring matching in `electrical_components_description`)

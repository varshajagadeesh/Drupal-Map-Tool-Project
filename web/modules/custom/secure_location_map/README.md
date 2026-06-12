# Secure Location Map

Secure Location Map is a reusable Drupal 10/11 custom module for searchable, filterable, dataset-scoped location maps. It imports CSV data into Drupal database tables and renders public maps with Leaflet and OpenStreetMap.

Press Forward and Bankcura use the same module but remain separate at every storage and query boundary. Each location and import record has a `dataset_id`; all public searches, updates, replace operations, and external ID lookups require that dataset ID.

This project is a Drupal module. It is not a WordPress plugin and does not use a separate FastAPI backend.

## Requirements

- An existing Drupal 10 or Drupal 11 site
- PHP and a database supported by that Drupal site
- The Drupal File module for admin-managed CSV uploads
- Browser access to the configured Leaflet and marker-cluster CDN URLs
- OpenStreetMap tile access, or a configured alternative tile provider
- Optional: Drush for command-line imports

Leaflet 1.9.4 and Leaflet.markercluster 1.5.3 are declared as external CDN libraries in `secure_location_map.libraries.yml`. Sites that prohibit CDN assets should download and host those libraries locally, then update the library definitions. Always follow the tile provider's usage policy and preserve attribution.

## Installation

1. Place this directory at `web/modules/custom/secure_location_map`.
2. Enable **Secure Location Map** at `/admin/modules` or run:

   ```bash
   drush en secure_location_map
   drush cr
   ```

3. Visit `/admin/config/services/secure-location-map`.
4. Review permissions at `/admin/people/permissions`.

On install, the module creates:

- `press_forward`: Press Forward Local Media Finder
- `bankcura`: Bank and Credit Union Finder

The `view secure location map` permission is granted to anonymous and authenticated roles during installation. Remove it from either role if maps should be restricted. Dataset administration and CSV upload permissions remain restricted.

## Public Pages

- Press Forward: `/local-media-finder`
- Bankcura: `/finance-location-finder`
- Generic map: `/secure-location-map/{dataset_machine_name}`
- Report view: `/secure-location-map/{dataset_machine_name}/report`
- GeoJSON API: `/secure-location-map/api/{dataset_machine_name}/locations`

The two friendly default paths are explicit Drupal routes. For a new dataset, use its generic map route or create a Drupal URL alias matching the dataset's configured public path.

## Dataset Management

Use `/admin/config/services/secure-location-map` to:

- Review dataset status, location count, last import, and public/report links
- Add a dataset
- Edit label, organization, center, zoom, allowed types, public path, and status
- Open the dataset-specific CSV upload page

Machine names cannot be changed after creation because they form stable public route identifiers. Enter allowed types as comma-separated or one-per-line machine values, such as `bank` and `credit_union`.

## CSV Imports

The upload form requires a dataset, CSV file, import mode, and optional default type. It also controls public email exposure and whether original columns are retained in metadata.

Import modes:

- **Replace** deletes locations only from the selected dataset, then imports valid rows.
- **Append** inserts all valid rows.
- **Update** updates by `external_id` only inside the selected dataset and inserts unmatched rows.

Every import stores totals and detailed row warnings/errors in `secure_location_map_import`. Duplicate external IDs within one upload are rejected and reported.

### Standard Columns

Required after automatic mapping:

- `name`
- `latitude`
- `longitude`

Supported standard fields include:

`external_id`, `name`, `type`, `category`, `address`, `city`, `state`, `zip`, `country`, `latitude`, `longitude`, `website`, `phone`, `email`, `hours`, `rating`, `review_count`

Recommended fields are `city`, `state`, `zip`, `type`, and `address`.

### Uploaded Source Mapping

The Press Forward source files `newspaper.csv`, `radio.csv`, and `television.csv` map automatically:

| Source | Stored field |
| --- | --- |
| `cid` | `external_id` |
| `title` | `name` |
| `postal_code` | `zip` |
| `review_rating` | `rating` |
| `categories` | `category`, type inference, and metadata |
| `attributes`, `socials`, `owner_name`, `description` | metadata |
| `emails` | metadata, or public `email` only when explicitly enabled |
| `price_range`, `is_verified` | corresponding location fields |

Choose the default import type for the supplied Press Forward files:

- `newspaper.csv`: `newspaper`
- `radio.csv`: `radio`
- `television.csv`: `television`

Categories such as `Newspaper publisher`, `Radio broadcaster`, and `Television station` are also inferred. Bankcura categories containing `Credit union`, `Bank`, or `Financial institution` infer `credit_union` or `bank`. The supplied `banks.csv` field `open_hours` maps to `hours`; additional source columns can be retained in metadata.

Malformed JSON-like fields do not crash the import. Their raw value is retained in metadata and the row receives a warning.

### Drush Imports

When Drush is installed:

```bash
drush slm:import press_forward /path/to/newspaper.csv --type=newspaper --mode=replace
drush slm:import press_forward /path/to/radio.csv --type=radio --mode=append
drush slm:import press_forward /path/to/television.csv --type=television --mode=append
drush slm:import bankcura /path/to/banks.csv --type=bank --mode=replace
```

Use `--mode=update` to update matching `external_id` values inside that dataset.

## API

The GeoJSON endpoint accepts:

- `q`: partial search across name, city, ZIP, and address
- `city`
- `zip`
- `type`: one type or comma-separated types
- `category`
- `lat`, `lng`, and `radius`: Haversine radius filtering in miles

The public map also has a **Starting address** field. Submitting an address resolves it through OpenStreetMap Nominatim, then keeps the active search/type/radius filters and sorts matching locations from nearest to farthest. Address lookup only runs when the user submits the field.
- `bbox`: `west,south,east,north`
- `limit`: bounded by global settings

The response is a GeoJSON `FeatureCollection`. It contains only public-safe fields. Email is returned only when exposure was explicitly enabled during import.

Example:

```text
/secure-location-map/api/press_forward/locations?q=Philadelphia&type=radio&limit=100
```

## Map Block

Place the **Secure Location Map** block through Drupal block layout. Block configuration supports:

- Dataset
- Title override
- Default radius
- Center and zoom overrides
- Result list, filter, and footer visibility
- Compact mode

The block uses the same Twig template and API as the public page and remains dataset-scoped.

## Reports

Open `/secure-location-map/{dataset_machine_name}/report`. Search and filters are encoded into the report URL so staff can share the same view. Use **Print / Save as PDF** for a landscape report, or capture the map, visible count, and legend after all markers have loaded.

## Configuration

Global settings at `/admin/config/services/secure-location-map/settings` control:

- Default and maximum API limits
- Marker clustering
- Default public-email upload checkbox
- Radius choices
- Tile URL
- Attribution text

The default result list renders at most 200 DOM items even when the API returns more markers. Refine filters to inspect a large result set.

## Security and Maintenance

- Admin routes use Drupal permissions.
- Database operations use Drupal's database API and parameterized conditions.
- Public queries and mutations are scoped by `dataset_id`.
- Uploaded files must have a `.csv` extension and are validated again by the importer.
- Coordinates and required fields are validated per row.
- Public frontend values are stripped of markup by the API and escaped again before popup HTML is created.
- Geolocation is requested only after a visitor clicks **Use my location**.
- CSV content is untrusted. Do not expose imported email unless it is appropriate for public display.
- Any future CSV export must prefix cells beginning with `=`, `+`, `-`, or `@` to prevent spreadsheet formula injection.
- Back up the three `secure_location_map_*` tables before bulk maintenance.

## Troubleshooting

**Missing latitude or longitude**  
The import requires mapped `latitude` and `longitude` headers and valid values on every accepted row.

**Malformed coordinates**  
Latitude must be between -90 and 90. Longitude must be between -180 and 180.

**Postal codes became numbers**  
ZIP/postal codes are stored as strings. Values like `19120.0` normalize to `19120`. Preserve leading zeros by exporting the spreadsheet column as text.

**Missing names**  
Use `name` or the automatically mapped `title` column. Rows without a name are rejected.

**Wrong dataset selected**  
Do not continue with a replace import. Select the correct dataset; replace mode affects only that selected dataset.

**Invalid file type**  
Export the file as CSV with a `.csv` extension. Spreadsheet workbook formats are not accepted.

**Map tiles or Leaflet do not load**  
Confirm the site can access the CDN and tile URLs, check the browser console for Content Security Policy errors, and host the libraries locally if required.

**Friendly path for a new dataset returns 404**  
Use `/secure-location-map/{machine_name}` or create a Drupal URL alias for the configured friendly path.

## Tests

Unit coverage is provided for Haversine distance, required column mapping, ZIP normalization, type inference, and invalid coordinates. From a full Drupal project:

```bash
php core/scripts/run-tests.sh --module secure_location_map --testsuite unit
```

The repository containing this module alone does not include Drupal core or its PHPUnit bootstrap.

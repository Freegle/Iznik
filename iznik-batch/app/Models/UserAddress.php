<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $userid
 * @property int|null $pafid
 * @property string|null $to
 * @property string|null $instructions
 * @property float|null $lat
 * @property float|null $lng
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress whereInstructions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress whereLat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress whereLng($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress wherePafid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress whereTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAddress whereUserid($value)
 * @mixin \Eloquent
 */
class UserAddress extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_addresses';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];

    /**
     * Get formatted address string from PAF data.
     *
     * @param string $delimiter Delimiter between address parts (', ' for single line, "\n" for multiline)
     */
    public function getFormatted(string $delimiter = ', '): ?string
    {
        if (!$this->pafid) {
            return null;
        }

        // Build query to get all PAF address parts with their reference values.
        $address = DB::table('paf_addresses')
            ->leftJoin('locations', 'locations.id', '=', 'paf_addresses.postcodeid')
            ->leftJoin('paf_posttown', 'paf_posttown.id', '=', 'paf_addresses.posttownid')
            ->leftJoin('paf_dependentlocality', 'paf_dependentlocality.id', '=', 'paf_addresses.dependentlocalityid')
            ->leftJoin('paf_doubledependentlocality', 'paf_doubledependentlocality.id', '=', 'paf_addresses.doubledependentlocalityid')
            ->leftJoin('paf_thoroughfaredescriptor', 'paf_thoroughfaredescriptor.id', '=', 'paf_addresses.thoroughfaredescriptorid')
            ->leftJoin('paf_dependentthoroughfaredescriptor', 'paf_dependentthoroughfaredescriptor.id', '=', 'paf_addresses.dependentthoroughfaredescriptorid')
            ->leftJoin('paf_buildingname', 'paf_buildingname.id', '=', 'paf_addresses.buildingnameid')
            ->leftJoin('paf_subbuildingname', 'paf_subbuildingname.id', '=', 'paf_addresses.subbuildingnameid')
            ->leftJoin('paf_pobox', 'paf_pobox.id', '=', 'paf_addresses.poboxid')
            ->leftJoin('paf_departmentname', 'paf_departmentname.id', '=', 'paf_addresses.departmentnameid')
            ->leftJoin('paf_organisationname', 'paf_organisationname.id', '=', 'paf_addresses.organisationnameid')
            ->select([
                'locations.name as postcode',
                'paf_addresses.buildingnumber',
                'paf_posttown.posttown',
                'paf_dependentlocality.dependentlocality',
                'paf_doubledependentlocality.doubledependentlocality',
                'paf_thoroughfaredescriptor.thoroughfaredescriptor',
                'paf_dependentthoroughfaredescriptor.dependentthoroughfaredescriptor',
                'paf_buildingname.buildingname',
                'paf_subbuildingname.subbuildingname',
                'paf_pobox.pobox',
                'paf_departmentname.departmentname',
                'paf_organisationname.organisationname',
            ])
            ->where('paf_addresses.id', $this->pafid)
            ->first();

        if (!$address) {
            return null;
        }

        // Build address lines following Royal Mail PAF formatting rules.
        $lines = [];

        // Organisation/department
        if ($address->organisationname) {
            $lines[] = $address->organisationname;
        }
        if ($address->departmentname) {
            $lines[] = $address->departmentname;
        }

        // PO Box
        if ($address->pobox) {
            $lines[] = 'PO Box ' . $address->pobox;
        }

        // Sub-building name (e.g., "Flat 1")
        if ($address->subbuildingname) {
            $lines[] = $address->subbuildingname;
        }

        // Building name or number + street
        $streetLine = '';
        if ($address->buildingname) {
            $lines[] = $address->buildingname;
        }
        if ($address->buildingnumber) {
            $streetLine = $address->buildingnumber;
        }
        if ($address->dependentthoroughfaredescriptor) {
            $streetLine .= ($streetLine ? ' ' : '') . $address->dependentthoroughfaredescriptor;
        }
        if ($address->thoroughfaredescriptor) {
            if ($streetLine && !$address->dependentthoroughfaredescriptor) {
                $streetLine .= ' ' . $address->thoroughfaredescriptor;
            } else {
                $lines[] = $streetLine;
                $streetLine = $address->thoroughfaredescriptor;
            }
        }
        if ($streetLine) {
            $lines[] = $streetLine;
        }

        // Locality
        if ($address->doubledependentlocality) {
            $lines[] = $address->doubledependentlocality;
        }
        if ($address->dependentlocality) {
            $lines[] = $address->dependentlocality;
        }

        // Post town and postcode
        $townPostcode = trim(($address->posttown ?? '') . ' ' . ($address->postcode ?? ''));
        if ($townPostcode) {
            $lines[] = $townPostcode;
        }

        // Filter empty lines and join
        $lines = array_filter($lines, fn($line) => !empty(trim($line)));

        // Apply tweaks similar to iznik-server
        if (count($lines) >= 2 && str_starts_with($lines[1] ?? '', ($lines[0] ?? '') . ' ')) {
            array_shift($lines);
        }

        return implode($delimiter, $lines);
    }

    /**
     * Get single-line formatted address.
     */
    public function getSingleLine(): ?string
    {
        return $this->getFormatted(', ');
    }

    /**
     * Get multi-line formatted address.
     */
    public function getMultiLine(): ?string
    {
        return $this->getFormatted("\n");
    }

    /**
     * Get coordinates for this address.
     * Falls back to postcode location if address doesn't have direct lat/lng.
     *
     * @return array [lat, lng] or [null, null]
     */
    public function getCoordinates(): array
    {
        // Use direct coordinates if available.
        if ($this->lat && $this->lng) {
            return [$this->lat, $this->lng];
        }

        // Fall back to postcode coordinates via PAF.
        if (!$this->pafid) {
            return [null, null];
        }

        $location = DB::table('paf_addresses')
            ->join('locations', 'locations.id', '=', 'paf_addresses.postcodeid')
            ->where('paf_addresses.id', $this->pafid)
            ->select(['locations.lat', 'locations.lng'])
            ->first();

        if ($location) {
            return [$location->lat, $location->lng];
        }

        return [null, null];
    }
}

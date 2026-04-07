<?php

namespace App\Services;

use App\Models\FileManager;
use App\Models\MaintenanceIssue;
use App\Models\Property;
use App\Models\PropertyDetail;
use App\Models\PropertyImage;
use App\Models\PropertyUnit;
use App\Models\PropertyUnitActivityLog;
use App\Models\PublicPropertyOption;
use App\Models\TenantUnitAssignment;
use App\Traits\ResponseTrait;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyService
{
    use ResponseTrait;

    public function __construct(
        private readonly UnitAvailabilityService $unitAvailabilityService = new UnitAvailabilityService()
    ) {
    }

    public function getAll()
    {
        $properties = Property::query()
            ->with(['propertyUnits', 'propertyDetail'])
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();

        return $this->appendAvailabilityToProperties($properties);
    }

    public function getAllData()
    {
        $properties = $this->getAll();

        return datatables($properties)
            ->addIndexColumn()
            ->addColumn('image', function ($property) {
                return '<img src="' . $property->thumbnail_image . '"
                class="rounded-circle avatar-md tbl-user-image"
                alt="">';
            })
            ->addColumn('name', function ($property) {
                return $property->name;
            })
            ->addColumn('address', function ($property) {
                return $property->propertyDetail?->address;
            })
            ->addColumn('unit', function ($property) {
                return $property->number_of_unit;
            })
            ->addColumn('rooms', function ($property) {
                return propertyTotalRoom($property->id);
            })
            ->addColumn('available', function ($property) {
                return $property->available_unit;
            })

            ->addColumn('action', function ($property) {
                return '<div class="tbl-action-btns d-inline-flex">
                            <a type="button" class="p-1 tbl-action-btn" href="' . route('owner.property.edit', $property->id) . '" title="' . __('Edit') . '"><span class="iconify" data-icon="clarity:note-edit-solid"></span></a>
                            <a type="button" class="p-1 tbl-action-btn" href="' . route('owner.property.show', $property->id) . '" title="' . __('View') . '"><span class="iconify" data-icon="carbon:view-filled"></span></a>
                            <button onclick="deleteItem(\'' . route('owner.property.delete', $property->id) . '\', \'allDataTable\')" class="p-1 tbl-action-btn"   title="' . __('Delete') . '"><span class="iconify" data-icon="ep:delete-filled"></span></button>
                        </div>';
            })
            ->rawColumns(['name', 'address', 'unit', 'rooms', 'image', 'available', 'action'])
            ->make(true);
    }

    public function allUnit()
    {
        return $this->unitAvailabilityService
            ->getUnits(['owner_user_id' => getOwnerUserId()])
            ->makeHidden(['updated_at', 'created_at', 'deleted_at']);
    }

    public function getAllCount()
    {
        return $this->getAll();
    }

    public function getById($id)
    {
        return $this->getEditablePropertyById($id);
    }

    public function getDetailsById($id)
    {
        $data = Property::query()
            ->leftJoin('property_details', 'properties.id', '=', 'property_details.property_id')
            ->leftJoin('users', function ($q) {
                $q->on('properties.maintainer_id', '=', 'users.id')->whereNull('users.deleted_at');
            })
            ->selectRaw('properties.*,
             property_details.lease_amount,
             property_details.lease_start_date,
             property_details.lease_end_date,
             property_details.country_id,
             property_details.state_id,
             property_details.city_id,
             property_details.zip_code,
             property_details.address,
             property_details.map_link,users.first_name,
             users.last_name')
            ->where('properties.owner_user_id', getOwnerUserId())
            ->findOrFail($id);

        $availabilitySummary = $this->unitAvailabilityService->getPropertySummaries([$id], getOwnerUserId())->get((int) $id, [
            'available_unit' => 0,
            'occupied_unit' => 0,
            'full_unit' => 0,
            'partial_unit' => 0,
            'vacant_unit' => 0,
            'on_hold_unit' => 0,
            'off_market_unit' => 0,
            'available_bedspace' => 0,
            'occupied_bedspace' => 0,
            'total_bedspace_capacity' => 0,
            'total_tenant' => 0,
        ]);
        $financialSummary = $this->getPropertyFinancialSummary((int) $id);

        foreach ($availabilitySummary as $key => $value) {
            $data->setAttribute($key, $value);
        }
        $data->setAttribute('avg_general_rent', $financialSummary->avg_general_rent ?? 0);
        $data->setAttribute('total_security_deposit', $financialSummary->total_security_deposit ?? 0);
        $data->setAttribute('total_late_fee', $financialSummary->total_late_fee ?? 0);

        return $data?->makeHidden(['updated_at', 'created_at', 'deleted_at']);
    }

    public function getByType($type)
    {
        $properties = Property::query()
            ->with(['propertyUnits', 'propertyDetail'])
            ->where('properties.property_type', $type)
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();

        return $this->appendAvailabilityToProperties($properties);
    }

    public function getByTypeCount($type)
    {
        return Property::query()
            ->where('property_type', $type)
            ->where('owner_user_id', getOwnerUserId())
            ->count();
    }

    public function getByTypeData($type)
    {
        $properties = $this->getByType($type);

        return datatables($properties)
            ->addIndexColumn()
            ->addColumn('image', function ($property) {
                return '<img src="' . $property->thumbnail_image . '"
                class="rounded-circle avatar-md tbl-user-image"
                alt="">';
            })
            ->addColumn('name', function ($property) {
                return $property->name;
            })
            ->addColumn('address', function ($property) {
                return $property->propertyDetail?->address;
            })
            ->addColumn('unit', function ($property) {
                return $property->number_of_unit;
            })
            ->addColumn('rooms', function ($property) {
                return propertyTotalRoom($property->id);
            })
            ->addColumn('available', function ($property) {
                return $property->available_unit;
            })
            ->addColumn('action', function ($property) {
                return '<div class="tbl-action-btns d-inline-flex">
                            <a type="button" class="p-1 tbl-action-btn" href="' . route('owner.property.edit', $property->id) . '" title="' . __('Edit') . '"><span class="iconify" data-icon="clarity:note-edit-solid"></span></a>
                            <a type="button" class="p-1 tbl-action-btn" href="' . route('owner.property.show', $property->id) . '" title="' . __('View') . '"><span class="iconify" data-icon="carbon:view-filled"></span></a>
                            <button onclick="deleteItem(\'' . route('owner.property.delete', $property->id) . '\', \'allDataTable\')" class="p-1 tbl-action-btn"   title="' . __('Delete') . '"><span class="iconify" data-icon="ep:delete-filled"></span></button>
                        </div>';
            })
            ->rawColumns(['name', 'address', 'unit', 'rooms', 'image', 'available', 'action'])
            ->make(true);
    }

    public function getPropertyIdsByMaintainerIds($id)
    {
        return Property::query()
            ->where('maintainer_id', $id)
            ->pluck('id')
            ->toArray();
    }

    public function getPropertyWithUnitsById($id)
    {
        try {
            $property = Property::query()
                ->join('property_details', 'properties.id', '=', 'property_details.property_id')
                ->select('properties.name', 'properties.id', 'properties.thumbnail_image_id', 'property_details.address')
                ->where('properties.owner_user_id', getOwnerUserId())
                ->findOrFail($id);
            $propertyUnits = $this->unitAvailabilityService
                ->getUnits([
                    'owner_user_id' => getOwnerUserId(),
                    'property_ids' => [$id],
                ])
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->unit_name,
                        'general_rent' => $unit->general_rent,
                        'security_deposit' => $unit->security_deposit,
                        'late_fee' => $unit->late_fee,
                        'security_deposit_type' => $unit->security_deposit_type,
                        'late_fee_type' => $unit->late_fee_type,
                        'incident_receipt' => $unit->incident_receipt,
                        'rent_type' => $unit->rent_type,
                        'monthly_due_day' => $unit->monthly_due_day,
                        'yearly_due_day' => $unit->yearly_due_day,
                        'max_occupancy' => $unit->max_occupancy,
                        'active_tenant_count' => (int) ($unit->active_tenant_count ?? 0),
                        'available_slots' => (int) ($unit->available_slots ?? 0),
                        'occupancy_label' => $unit->occupancy_label,
                        'availability_label' => $unit->availability_label,
                        'is_available_for_assignment' => (bool) ($unit->is_available_for_assignment ?? false),
                        'manual_availability_status' => $unit->manual_availability_status ?? PropertyUnit::MANUAL_AVAILABILITY_ACTIVE,
                    ];
                })
                ->values();

            $data = $property;
            $data->units = $propertyUnits;
            $data->image = $property->thumbnail_image;
            return $this->success($data);
        } catch (\Exception $e) {
            $message = getErrorMessage($e, $e->getMessage());
            return $this->error([], $message);
        }
    }

    public function propertyInformationStore($request)
    {
        DB::beginTransaction();
        try {
            if ($request->property_id) {
                $property = Property::with('propertyDetail')->where('owner_user_id', getOwnerUserId())->where('id', $request->property_id)->firstOrFail();
            } else {
                if (getOwnerLimit(RULES_PROPERTY) < 1) {
                    throw new Exception(__('Your property Limit finished'));
                }
                $property = new Property();
            }
            $property->property_type = $request->property_type;
            $property->owner_user_id = getOwnerUserId();
            $property->name = ($request->property_type == PROPERTY_TYPE_OWN) ? $request->own_property_name : $request->lease_property_name;
            $property->number_of_unit = ($request->property_type == PROPERTY_TYPE_OWN) ? $request->own_number_of_unit : $request->lease_number_of_unit;
            $property->description = ($request->property_type == PROPERTY_TYPE_OWN) ? $request->own_description : $request->lease_description;
            $property->is_public = $request->boolean('is_public');
            $property->public_slug = $request->boolean('is_public')
                ? $this->resolvePublicSlug($property->name, $request->public_slug ?: $property->public_slug, $property->id ?: null)
                : null;
            $property->public_category = $request->boolean('is_public')
                ? $request->input('public_category')
                : null;
            $property->public_summary = $request->boolean('is_public')
                ? $this->resolvePublicSummary($request->public_summary, $property->description, $property->name)
                : null;
            $property->public_home_sections = $request->boolean('is_public')
                ? implode(',', $request->input('public_home_sections', []))
                : null;
            $property->public_sort_order = $request->boolean('is_public')
                ? (int) $request->input('public_sort_order', 0)
                : 0;
            $property->save();

            $propertyDetail = PropertyDetail::wherePropertyId($property->id)->first();
            if (!$propertyDetail) {
                $propertyDetail = new PropertyDetail();
            }
            $propertyDetail->property_id = $property->id;
            $propertyDetail->lease_amount = ($request->property_type == PROPERTY_TYPE_LEASE) ? $request->lease_amount : 0;
            $propertyDetail->lease_start_date = ($request->property_type == PROPERTY_TYPE_LEASE && !empty($request->lease_start_date)) ? date('Y-m-d', strtotime($request->lease_start_date)) : null;
            $propertyDetail->lease_end_date = ($request->property_type == PROPERTY_TYPE_LEASE && !empty($request->lease_end_date)) ? date('Y-m-d', strtotime($request->lease_end_date)) : null;
            $propertyDetail->save();

            $this->syncWholePropertyPublicOption($property, $request);
            $this->normalizePublicWebsiteMetadata($property);
            $this->normalizePublicOptionOrderingAndDefault($property);
            DB::commit();

            $locationService = new LocationService;
            $response['countries'] = $locationService->getCountry()->getData()->data;
            $response['property'] = $property;
            $response['message'] = $request->property_id ? __(UPDATED_SUCCESSFULLY) : __(CREATED_SUCCESSFULLY);
            $response['step'] = LOCATION_ACTIVE_CLASS;
            $response['view'] = view('owner.property.partial.render-location', $response)->render();
            return $this->success($response);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], $e->getMessage());
        }
    }

    public function locationStore($request)
    {
        DB::beginTransaction();
        try {
            $property = Property::where('owner_user_id', getOwnerUserId())->findOrFail($request->property_id);
            $propertyDetail = PropertyDetail::wherePropertyId($property->id)->first();
            if (!$propertyDetail) {
                $propertyDetail = new PropertyDetail();
            }
            $propertyDetail->country_id = $request->country_id;
            $propertyDetail->state_id = $request->state_id;
            $propertyDetail->city_id = $request->city_id;
            $propertyDetail->zip_code = $request->zip_code;
            $propertyDetail->address = $request->address;
            $propertyDetail->map_link = $request->map_link;
            $propertyDetail->save();

            DB::commit();
            $response['property'] = $property;
            $response['message'] = __(UPDATED_SUCCESSFULLY);
            $response['propertyUnits'] = PropertyUnit::where('property_id', $property->id)->get();
            $response['step'] = UNIT_ACTIVE_CLASS;
            $response['view'] = view('owner.property.partial.render-unit', $response)->render();
            return $this->success($response);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], getErrorMessage($e));
        }
    }

    public function unitStore($request)
    {
        DB::beginTransaction();
        try {
            $property = Property::where('owner_user_id', getOwnerUserId())->findOrFail($request->property_id);
            $property->unit_type = $request->unit_type;
            $property->save();

            $notDeletedIds = array();
            if ($request->unit_type == PROPERTY_UNIT_TYPE_SINGLE) {
                for ($i = 0; $i < count($request->single['unit_name']); $i++) {
                    $property_unit = PropertyUnit::find($request->single['id'][$i]);
                    array_push($notDeletedIds, $request->single['id'][$i]);
                    if (!$property_unit) {
                        if (getOwnerLimit(RULES_UNIT) < 1) {
                            throw new Exception(__('Your unit Limit finished'));
                        }
                        $property_unit = new PropertyUnit();
                    }
                    $property_unit->property_id = $property->id;
                    $property_unit->unit_name = $request->single['unit_name'][$i];
                    $property_unit->bedroom = $request->single['bedroom'][$i];
                    $property_unit->bath = $request->single['bath'][$i];
                    $property_unit->kitchen = $request->single['kitchen'][$i];
                    $property_unit->max_occupancy = $request->single['max_occupancy'][$i] ?? 1;
                    $property_unit->save();
                }
            } else {
                for ($i = 0; $i < count($request->multiple['unit_name']); $i++) {
                    $property_unit = PropertyUnit::find((int) $request->multiple['id'][$i]);
                    array_push($notDeletedIds, $request->multiple['id'][$i]);
                    if (!$property_unit) {
                        if (getOwnerLimit(RULES_UNIT) < 1) {
                            throw new Exception(__('Your unit Limit finished'));
                        }
                        $property_unit = new PropertyUnit();
                    }
                    $property_unit->property_id = $property->id;
                    $property_unit->unit_name = $request->multiple['unit_name'][$i];
                    $property_unit->bedroom = $request->multiple['bedroom'][$i];
                    $property_unit->bath = $request->multiple['bath'][$i];
                    $property_unit->kitchen = $request->multiple['kitchen'][$i];
                    $property_unit->max_occupancy = $request->multiple['max_occupancy'][$i] ?? 1;
                    $property_unit->square_feet = $request->multiple['square_feet'][$i];
                    $property_unit->amenities = $request->multiple['amenities'][$i];
                    $property_unit->condition = $request->multiple['condition'][$i];
                    $property_unit->parking = $request->multiple['parking'][$i];
                    $property_unit->description = $request->multiple['description'][$i];
                    $property_unit->save();

                    if (isset($request->multiple['images'][$i])) {
                        $exitFile = FileManager::where('origin_type', 'App\Models\PropertyUnit')->where('origin_id', $property_unit->id)->first();
                        if ($exitFile) {
                            $exitFile->removeFile();
                            $upload = $exitFile->updateUpload($exitFile->id, 'PropertyUnit', $request->multiple['images'][$i], $property_unit->id);
                        } else {
                            $newFile = new FileManager();
                            $upload = $newFile->upload('PropertyUnit', $request->multiple['images'][$i], $property_unit->id);
                        }

                        if ($upload['status']) {
                            $upload['file']->origin_id = $property_unit->id;
                            $upload['file']->origin_type = "App\Models\PropertyUnit";
                            $upload['file']->save();
                        } else {
                            throw new Exception($upload['message']);
                        }
                    }
                }
            }

            PropertyUnit::whereNotIn('id', $notDeletedIds)->where('property_id', $property->id)->get()->map(function ($q) {
                $q->delete();
            });

            DB::commit();
            $response['property'] = $property;
            $response['propertyUnits'] = PropertyUnit::where('property_id', $response['property']->id)->get();
            $response['propertyUnitIds'] = PropertyUnit::where('property_id', $response['property']->id)->pluck('id')->toArray();
            $response['publicOptionsByUnit'] = PublicPropertyOption::query()
                ->where('property_id', $response['property']->id)
                ->whereNotNull('property_unit_id')
                ->get()
                ->keyBy('property_unit_id');
            $response['message'] = __(UPDATED_SUCCESSFULLY);
            $response['step'] = RENT_CHARGE_ACTIVE_CLASS;
            $response['view'] = view('owner.property.partial.render-rent-charge', $response)->render();
            return $this->success($response);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], $e->getMessage());
        }
    }

    public function rentChargeStore($request)
    {
        DB::beginTransaction();
        try {
            $property = Property::where('owner_user_id', getOwnerUserId())->findOrFail($request->property_id);

            for ($i = 0; $i < count($request->propertyUnit['id']); $i++) {
                $property_unit = PropertyUnit::find($request->propertyUnit['id'][$i]);
                $property_unit->general_rent = $request->propertyUnit['general_rent'][$i] ?? 0;
                $property_unit->security_deposit_type = $request->propertyUnit['security_deposit_type'][$i] ?? 0;
                $property_unit->security_deposit = $request->propertyUnit['security_deposit'][$i] ?? 0;
                $property_unit->late_fee_type = $request->propertyUnit['late_fee_type'][$i] ?? 0;
                $property_unit->late_fee = $request->propertyUnit['late_fee'][$i] ?? 0;
                $property_unit->incident_receipt = $request->propertyUnit['incident_receipt'][$i] ?? 0;
                $property_unit->rent_type = $request->propertyUnit['rent_type'][$i];
                $property_unit->monthly_due_day = ($request->propertyUnit['rent_type'][$i] == PROPERTY_UNIT_RENT_TYPE_MONTHLY) ? $request->propertyUnit['monthly_due_day'][$i] : null;
                $property_unit->yearly_due_day = ($request->propertyUnit['rent_type'][$i] == PROPERTY_UNIT_RENT_TYPE_YEARLY) ? $request->propertyUnit['yearly_due_day'][$i] : null;
                $property_unit->lease_start_date = ($request->propertyUnit['rent_type'][$i] == PROPERTY_UNIT_RENT_TYPE_CUSTOM) ? date('Y-m-d', strtotime($request->propertyUnit['lease_start_date'][$i])) : null;
                $property_unit->lease_end_date = ($request->propertyUnit['rent_type'][$i] == PROPERTY_UNIT_RENT_TYPE_CUSTOM) ? date('Y-m-d', strtotime($request->propertyUnit['lease_end_date'][$i])) : null;
                $property_unit->lease_payment_due_date = ($request->propertyUnit['rent_type'][$i] == PROPERTY_UNIT_RENT_TYPE_CUSTOM) ? date('Y-m-d', strtotime($request->propertyUnit['lease_payment_due_date'][$i])) : null;
                $property_unit->save();

                $this->syncUnitPublicOption($property, $property_unit, $request, $i);
            }
            $this->normalizePublicWebsiteMetadata($property);
            $this->normalizePublicOptionOrderingAndDefault($property);
            DB::commit();
            $response['property'] = $property;
            $response['message'] = __(UPDATED_SUCCESSFULLY);
            $response['step'] = IMAGE_ACTIVE_CLASS;
            $response['view'] = view('owner.property.partial.render-image', $response)->render();
            return $this->success($response);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], getErrorMessage($e));
        }
    }

    public function imageStore($request, $id)
    {
        DB::beginTransaction();
        try {
            $property = Property::where('owner_user_id', getOwnerUserId())->findOrFail($id);
            /*File Manager Call upload*/
            if ($request->file('file')) {
                $new_file = new FileManager();
                $upload = $new_file->upload('PropertyImage', $request->file);

                if ($upload['status']) {
                    $propertyImage = new PropertyImage();
                    $propertyImage->property_id = $property->id;
                    $propertyImage->file_id = $upload['file']->id;
                    $propertyImage->save();

                    $upload['file']->origin_id = $propertyImage->id;
                    $upload['file']->origin_type = "App\Models\PropertyImage";
                    $upload['file']->save();
                } else {
                    throw new Exception($upload['message']);
                }
            }
            /*End*/

            DB::commit();
            $property = $property;
            return $this->success($property, __(UPLOADED_SUCCESSFULLY));
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], $e->getMessage());
        }
    }

    public function imageDelete($id)
    {
        DB::beginTransaction();
        try {
            $existsImage = PropertyImage::query()
                ->join('properties', 'property_images.property_id', '=', 'properties.id')
                ->where('property_images.id', $id)
                ->where('properties.owner_user_id', getOwnerUserId())
                ->exists();
            if ($existsImage) {
                $propertyImage = PropertyImage::findOrFail($id);
                $file = FileManager::where('origin_type', 'App\Models\PropertyImage')->where('origin_id', $id)->first();
                if ($file) {
                    $file->removeFile();
                    $file->delete();
                    $propertyImage->delete();
                }
                DB::commit();
                return $this->success([], __(DELETED_SUCCESSFULLY));
            } else {
                throw new Exception(__(SOMETHING_WENT_WRONG));
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], $e->getMessage());
        }
    }

    public function thumbnailImageUpdate($request, $id)
    {
        DB::beginTransaction();
        try {
            /*File Manager Call upload for Thumbnail Image*/
            $property = Property::where('owner_user_id', getOwnerUserId())->findOrFail($id);
            if ($request->file) {
                $new_file = new FileManager();
                $upload = $new_file->upload('Property', $request->file);

                if ($upload['status']) {
                    $property->thumbnail_image_id = $upload['file']->id;
                    $property->save();

                    $upload['file']->origin_type = "App\Models\Property";
                    $upload['file']->origin_id = $property->id;
                    $upload['file']->save();
                } else {
                    throw new Exception($upload['message']);
                }
            }
            /*End*/
            DB::commit();
            return $this->success([], __(UPLOADED_SUCCESSFULLY));
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], $e->getMessage());
        }
    }

    public function getPropertyInformation($request)
    {
        DB::beginTransaction();
        try {
            $response = [];
            if ($request->property_id) {
                $response['property'] = $this->getEditablePropertyById($request->property_id);
            }

            $view = view('owner.property.partial.render-property-information', $response)->render();
            DB::commit();
            return $this->success($view);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([], $e->getMessage());
        }
    }

    public function getLocation($request)
    {
        try {
            $response['property'] = $this->getEditablePropertyById($request->property_id);
            $country_file = public_path('file/countries.csv');
            $response['countries'] = csvToArray($country_file);
            $response['view'] = view('owner.property.partial.render-location', $response)->render();
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error([], getErrorMessage($e));
        }
    }

    public function getUnitByPropertyId($request)
    {
        try {
            $response['property'] = $this->getEditablePropertyById($request->property_id);
            $response['propertyUnits'] = PropertyUnit::where('property_id', $response['property']->id)->get();
            $response['view'] = view('owner.property.partial.render-unit', $response)->render();
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error([], getErrorMessage($e));
        }
    }

    public function getUnitByPropertyIds($request)
    {
        try {
            $propertiesIds = Property::query()
                ->when(!in_array('all', $request->property_ids ?? []), function ($q) use ($request) {
                    $q->whereIn('id', $request->property_ids ?? []);
                })
                ->where('owner_user_id', getOwnerUserId())
                ->select('id')
                ->pluck('id')
                ->toArray();
            $data['units'] = PropertyUnit::whereIn('property_id', $propertiesIds ?? [])->get();
            return $this->success($data);
        } catch (\Exception $e) {
            return $this->error([], getErrorMessage($e));
        }
    }

    public function getRentCharge($request)
    {
        try {
            $response['property'] = $this->getEditablePropertyById($request->property_id);
            $response['propertyUnits'] = PropertyUnit::where('property_id', $response['property']->id)->get();
            $response['propertyUnitIds'] = PropertyUnit::where('property_id', $response['property']->id)->pluck('id')->toArray();
            $response['publicOptionsByUnit'] = PublicPropertyOption::query()
                ->where('property_id', $response['property']->id)
                ->whereNotNull('property_unit_id')
                ->get()
                ->keyBy('property_unit_id');
            $response['view'] = view('owner.property.partial.render-rent-charge', $response)->render();
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error([], getErrorMessage($e));
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $property = Property::where('owner_user_id', getOwnerUserId())->findOrFail($id);
            $unitIds = PropertyUnit::query()
                ->where('property_id', $property->id)
                ->pluck('id');

            $activeAssignmentExists = TenantUnitAssignment::query()
                ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
                ->where('tenant_unit_assignments.property_id', $id)
                ->where('tenants.status', TENANT_STATUS_ACTIVE)
                ->when($this->unitAvailabilityService->supportsTemporalAssignments(), function ($query) {
                    $query->where('tenant_unit_assignments.is_current', true);
                })
                ->exists();
            if ($activeAssignmentExists) {
                throw new Exception(__('Active tenant assignments exist. Move tenants out before retiring this property.'));
            }

            $historicalAssignmentExists = TenantUnitAssignment::query()
                ->where('property_id', $id)
                ->exists();
            $activityLogExists = PropertyUnitActivityLog::query()
                ->whereIn('unit_id', $unitIds)
                ->exists();
            $publicOptionExists = PublicPropertyOption::query()
                ->where('property_id', $id)
                ->exists();
            if ($historicalAssignmentExists || $activityLogExists || $publicOptionExists) {
                throw new Exception(__('This property has unit history or website usage and cannot be deleted.'));
            }

            if ($property) {
                foreach (@$property->propertyImages as $propertyImage) {
                    $propertyImage = PropertyImage::find($propertyImage->id);
                    $fileManager = FileManager::find($propertyImage->file_id);
                    if ($propertyImage && $fileManager) {
                        $fileManager->removeFile();
                        $fileManager->delete();
                        $propertyImage->delete();
                    }
                }
                if ($property->propertyDetail) {
                    $property->propertyDetail->delete();
                }
                PropertyUnit::where('property_id', $property->id)->delete();
                $property->delete();
            }
            DB::commit();
            return redirect()->back()->with('success', __(DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function getUnitsByPropertyId($id)
    {
        $propertyUnits = $this->unitAvailabilityService->getUnits([
            'owner_user_id' => getOwnerUserId(),
            'property_ids' => [$id],
        ]);
        return $this->success($propertyUnits);
    }

    public function unitDelete($id)
    {
        try {
            $activeAssignmentExists = TenantUnitAssignment::query()
                ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
                ->where('tenant_unit_assignments.unit_id', $id)
                ->where('tenants.status', TENANT_STATUS_ACTIVE)
                ->when($this->unitAvailabilityService->supportsTemporalAssignments(), function ($query) {
                    $query->where('tenant_unit_assignments.is_current', true);
                })
                ->exists();
            if ($activeAssignmentExists) {
                throw new Exception(__('Active tenant assignments exist. Move tenants out before retiring this unit.'));
            }

            $propertyIds = Property::query()
                ->where('owner_user_id', getOwnerUserId())
                ->withTrashed()
                ->select('id')
                ->get()
                ->pluck('id')
                ->toArray();

            $unit = PropertyUnit::query()
                ->whereIn('property_id', $propertyIds)
                ->find($id);

            if ($unit) {
                $historicalAssignmentExists = TenantUnitAssignment::query()
                    ->where('unit_id', $id)
                    ->exists();
                $activityLogExists = PropertyUnitActivityLog::query()
                    ->where('unit_id', $id)
                    ->exists();
                $publicOptionExists = PublicPropertyOption::query()
                    ->where('property_unit_id', $id)
                    ->exists();

                if ($historicalAssignmentExists || $activityLogExists || $publicOptionExists) {
                    throw new Exception(__('This unit has history or website usage and cannot be deleted.'));
                }

                $unit->delete();
            } else {
                throw new Exception(__('No Data Found'));
            }
            return redirect()->back()->with('success', __(DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function getPropertySearch($type, $searchItem)
    {
        $properties = Property::query()
            ->where('properties.property_type', $type)
            ->where('properties.name', 'LIKE', "%{$searchItem}%")
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();

        return $this->appendAvailabilityToProperties($properties);
    }

    private function syncWholePropertyPublicOption(Property $property, $request): void
    {
        if (! $property->is_public) {
            PublicPropertyOption::query()
                ->where('property_id', $property->id)
                ->delete();
            return;
        }

        $option = PublicPropertyOption::query()
            ->where('property_id', $property->id)
            ->whereNull('property_unit_id')
            ->first();

        if (! $request->boolean('enable_whole_property_option')) {
            if ($option) {
                $option->delete();
            }
            return;
        }

        if (! $option) {
            $option = new PublicPropertyOption();
        }

        $option->property_id = $property->id;
        $option->property_unit_id = null;
        $option->rental_kind = $request->input('whole_property_option.rental_kind', 'whole_property');
        $option->monthly_rate = $this->nullableNumeric($request->input('whole_property_option.monthly_rate'));
        $option->nightly_rate = $this->nullableNumeric($request->input('whole_property_option.nightly_rate'));
        $option->max_guests = $this->nullableInteger($request->input('whole_property_option.max_guests'));
        $option->status = ACTIVE;
        $option->sort_order = 0;
        $option->is_default = false;
        $option->save();
    }

    private function getEditablePropertyById($id): Property
    {
        return Property::query()
            ->with(['propertyDetail', 'wholePublicOption'])
            ->where('owner_user_id', getOwnerUserId())
            ->findOrFail($id);
    }

    private function syncUnitPublicOption(Property $property, PropertyUnit $unit, $request, int $index): void
    {
        $option = PublicPropertyOption::query()
            ->where('property_id', $property->id)
            ->where('property_unit_id', $unit->id)
            ->first();

        $enabled = $property->is_public && ((string) data_get($request->input('propertyUnit.public_enabled', []), $index, '0') === '1');

        if (! $enabled) {
            if ($option) {
                $option->delete();
            }
            return;
        }

        if (! $option) {
            $option = new PublicPropertyOption();
        }

        $option->property_id = $property->id;
        $option->property_unit_id = $unit->id;
        $option->rental_kind = data_get($request->input('propertyUnit.public_rental_kind', []), $index, 'whole_unit');
        $option->monthly_rate = $this->nullableNumeric(
            data_get($request->input('propertyUnit.public_monthly_rate', []), $index, $unit->general_rent)
        );
        $option->nightly_rate = $this->nullableNumeric(
            data_get($request->input('propertyUnit.public_nightly_rate', []), $index)
        );
        $publicMaxGuests = $this->nullableInteger(
            data_get($request->input('propertyUnit.public_max_guests', []), $index)
        );

        if (is_null($unit->max_occupancy) && ! is_null($publicMaxGuests)) {
            $unit->max_occupancy = $publicMaxGuests;
            $unit->save();
        }

        $effectiveMaxGuests = $publicMaxGuests ?? $this->nullableInteger($unit->max_occupancy);
        if (is_null($effectiveMaxGuests)) {
            throw new Exception(__('Set max occupancy or max guests for :unit before enabling it on the website.', [
                'unit' => $unit->unit_name ?: __('this unit'),
            ]));
        }

        $option->max_guests = $effectiveMaxGuests;
        $option->status = ACTIVE;
        $option->sort_order = $index + 1;
        $option->is_default = false;
        $option->save();
    }

    private function appendAvailabilityToProperties($properties)
    {
        $propertyIds = $properties->pluck('id')->map(fn ($id) => (int) $id)->all();
        $availabilitySummaries = $this->unitAvailabilityService->getPropertySummaries($propertyIds, getOwnerUserId());
        $roomSummaries = PropertyUnit::query()
            ->whereIn('property_id', $propertyIds)
            ->whereNull('deleted_at')
            ->selectRaw('property_id, COALESCE(SUM(bedroom), 0) as rooms')
            ->groupBy('property_id')
            ->pluck('rooms', 'property_id');
        $maintainerSummaries = DB::table('maintainers')
            ->whereIn('property_id', $propertyIds)
            ->selectRaw('property_id, COUNT(DISTINCT id) as total')
            ->groupBy('property_id')
            ->pluck('total', 'property_id');

        foreach ($properties as $property) {
            $summary = $availabilitySummaries->get((int) $property->id, [
                'available_unit' => 0,
                'occupied_unit' => 0,
                'full_unit' => 0,
                'partial_unit' => 0,
                'vacant_unit' => 0,
                'on_hold_unit' => 0,
                'off_market_unit' => 0,
                'available_bedspace' => 0,
                'occupied_bedspace' => 0,
                'total_bedspace_capacity' => 0,
                'total_tenant' => 0,
            ]);

            foreach ($summary as $key => $value) {
                $property->setAttribute($key, $value);
            }
            $property->setAttribute('address', $property->propertyDetail?->address);
            $property->setAttribute('rooms', (int) ($roomSummaries[$property->id] ?? 0));
            $property->setAttribute('total_maintainers', (int) ($maintainerSummaries[$property->id] ?? 0));
        }

        return $properties->makeHidden(['updated_at', 'created_at', 'deleted_at']);
    }

    private function getPropertyFinancialSummary(int $propertyId): object
    {
        $assignmentQuery = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->where('tenant_unit_assignments.property_id', $propertyId)
            ->where('tenants.owner_user_id', getOwnerUserId())
            ->where('tenants.status', TENANT_STATUS_ACTIVE);

        if ($this->unitAvailabilityService->supportsTemporalAssignments()) {
            $assignmentQuery->where('tenant_unit_assignments.is_current', true);
        }

        $tenantFinancialQuery = $assignmentQuery
            ->selectRaw(
                'tenant_unit_assignments.tenant_id,
                MAX(tenants.general_rent) as general_rent,
                MAX(tenants.security_deposit) as security_deposit,
                MAX(tenants.late_fee) as late_fee'
            )
            ->groupBy('tenant_unit_assignments.tenant_id');

        return DB::query()
            ->fromSub($tenantFinancialQuery, 'tenant_financial_summary')
            ->selectRaw(
                'COUNT(*) as total_tenant,
                COALESCE(AVG(general_rent), 0) as avg_general_rent,
                COALESCE(SUM(security_deposit), 0) as total_security_deposit,
                COALESCE(SUM(late_fee), 0) as total_late_fee'
            )
            ->first();
    }

    private function nullableNumeric($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableInteger($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizePublicWebsiteMetadata(Property $property): void
    {
        if (! $property->is_public) {
            return;
        }

        $didChange = false;

        $resolvedSlug = $this->resolvePublicSlug($property->name, $property->public_slug, $property->id);
        if ($property->public_slug !== $resolvedSlug) {
            $property->public_slug = $resolvedSlug;
            $didChange = true;
        }

        $resolvedSummary = $this->resolvePublicSummary($property->public_summary, $property->description, $property->name);
        if ($property->public_summary !== $resolvedSummary) {
            $property->public_summary = $resolvedSummary;
            $didChange = true;
        }

        if (is_null($property->public_sort_order)) {
            $property->public_sort_order = 0;
            $didChange = true;
        }

        if ($didChange) {
            $property->save();
        }
    }

    private function normalizePublicOptionOrderingAndDefault(Property $property): void
    {
        if (! $property->is_public) {
            return;
        }

        $activeOptions = PublicPropertyOption::query()
            ->where('property_id', $property->id)
            ->where('status', ACTIVE)
            ->orderByRaw('CASE WHEN property_unit_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('property_unit_id')
            ->orderBy('id')
            ->get();

        if ($activeOptions->isEmpty()) {
            return;
        }

        foreach ($activeOptions->values() as $index => $option) {
            $expectedOrder = $index;
            if ((int) $option->sort_order !== $expectedOrder) {
                $option->sort_order = $expectedOrder;
                $option->save();
            }
        }

        $defaultOption = $activeOptions
            ->sort(function (PublicPropertyOption $left, PublicPropertyOption $right) {
                $leftMonthly = $left->monthly_rate ?? INF;
                $rightMonthly = $right->monthly_rate ?? INF;

                if ($leftMonthly !== $rightMonthly) {
                    return $leftMonthly <=> $rightMonthly;
                }

                $leftNightly = $left->nightly_rate ?? INF;
                $rightNightly = $right->nightly_rate ?? INF;

                if ($leftNightly !== $rightNightly) {
                    return $leftNightly <=> $rightNightly;
                }

                return $left->id <=> $right->id;
            })
            ->first();

        PublicPropertyOption::query()
            ->where('property_id', $property->id)
            ->update(['is_default' => false]);

        if ($defaultOption) {
            $defaultOption->is_default = true;
            $defaultOption->save();
        }
    }

    private function resolvePublicSlug(string $propertyName, ?string $requestedSlug, ?int $propertyId = null): string
    {
        $baseSlug = Str::slug(trim((string) $requestedSlug) ?: $propertyName) ?: 'property';
        $slug = $baseSlug;
        $suffix = 2;

        while (
            Property::query()
                ->where('public_slug', $slug)
                ->when($propertyId, fn ($query) => $query->where('id', '!=', $propertyId))
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function resolvePublicSummary(?string $requestedSummary, ?string $description, string $propertyName): string
    {
        $summary = trim((string) $requestedSummary);

        if ($summary !== '') {
            return $summary;
        }

        $fallbackDescription = trim((string) $description);

        return $fallbackDescription !== '' ? $fallbackDescription : $propertyName;
    }

    public function getUnitId(){

        return PropertyUnit::all();
    }
    public function getPropertyId()
    {
        return Property::all();
    }
    public function getAllIssue()
    {
        return MaintenanceIssue::all();
    }

}

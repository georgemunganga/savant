<?php

namespace App\Services;

use App\Models\FileManager;
use App\Models\MaintenanceIssue;
use App\Models\Property;
use App\Models\PropertyDetail;
use App\Models\PropertyImage;
use App\Models\PropertyUnit;
use App\Models\TenantUnitAssignment;
use App\Traits\ResponseTrait;
use Exception;
use Illuminate\Support\Facades\DB;

class PropertyService
{
    use ResponseTrait;

    public function getAll()
    {
        $occupiedSummarySql = "(select tenant_unit_assignments.property_id, COUNT(DISTINCT tenant_unit_assignments.unit_id) as occupied_unit
            from tenant_unit_assignments
            join tenants on tenants.id = tenant_unit_assignments.tenant_id
            join users on users.id = tenants.user_id
            where users.deleted_at IS NULL and tenants.status = " . TENANT_STATUS_ACTIVE . " and tenants.owner_user_id = " . getOwnerUserId() . "
            group by tenant_unit_assignments.property_id) as occupied_summary";
        $roomSummarySql = "(select property_units.property_id, SUM(property_units.bedroom) as rooms
            from property_units
            where property_units.deleted_at IS NULL
            group by property_units.property_id) as room_summary";

        $data = Property::query()
            ->with('propertyUnits')
            ->leftJoin(DB::raw($occupiedSummarySql), 'occupied_summary.property_id', '=', 'properties.id')
            ->leftJoin(DB::raw($roomSummarySql), 'room_summary.property_id', '=', 'properties.id')
            ->selectRaw('GREATEST(properties.number_of_unit - COALESCE(occupied_summary.occupied_unit, 0), 0) as available_unit, COALESCE(room_summary.rooms, 0) as rooms, properties.*')
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();
        return $data?->makeHidden(['updated_at', 'created_at', 'deleted_at']);
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
        $activeTenantSummary = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->where('tenants.status', TENANT_STATUS_ACTIVE)
            ->where('tenants.owner_user_id', getOwnerUserId())
            ->whereNull('users.deleted_at')
            ->selectRaw("tenant_unit_assignments.unit_id, COUNT(DISTINCT tenant_unit_assignments.tenant_id) as active_tenant_count, GROUP_CONCAT(DISTINCT CONCAT(users.first_name, ' ', users.last_name) ORDER BY users.first_name SEPARATOR ', ') as active_tenant_names, MIN(tenants.id) as first_tenant_id")
            ->groupBy('tenant_unit_assignments.unit_id');

        $data = PropertyUnit::query()
            ->join('properties', ['property_units.property_id' => 'properties.id'])
            ->leftJoinSub($activeTenantSummary, 'tenant_summary', function ($join) {
                $join->on('property_units.id', '=', 'tenant_summary.unit_id');
            })
            ->leftJoin('file_managers', ['property_units.id' => 'file_managers.origin_id', 'file_managers.origin_type' => (DB::raw("'App\\\Models\\\PropertyUnit'"))])
            ->select(
                'property_units.*',
                'properties.name as property_name',
                'file_managers.file_name',
                'file_managers.folder_name',
                'tenant_summary.active_tenant_count',
                'tenant_summary.active_tenant_names',
                'tenant_summary.first_tenant_id'
            )
            ->orderBy('properties.id', 'asc')
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();
        return $data?->makeHidden(['updated_at', 'created_at', 'deleted_at']);
    }

    public function getAllCount()
    {
        $tenantPropertySummarySql = "(select tenant_unit_assignments.property_id,
            COUNT(DISTINCT tenant_unit_assignments.tenant_id) as total_tenant,
            COUNT(DISTINCT tenant_unit_assignments.unit_id) as occupied_unit
            from tenant_unit_assignments
            join tenants on tenants.id = tenant_unit_assignments.tenant_id
            join users on users.id = tenants.user_id
            where users.deleted_at IS NULL and tenants.status = " . TENANT_STATUS_ACTIVE . " and tenants.owner_user_id = " . getOwnerUserId() . "
            group by tenant_unit_assignments.property_id) as tenant_property_summary";

        $data = Property::query()
            ->leftJoin(DB::raw($tenantPropertySummarySql), 'tenant_property_summary.property_id', '=', 'properties.id')
            ->leftJoin('property_details', 'properties.id', '=', 'property_details.property_id')
            ->leftJoin('maintainers', 'properties.id', '=', 'maintainers.property_id')
            ->selectRaw('COALESCE(tenant_property_summary.total_tenant, 0) as total_tenant, COALESCE(tenant_property_summary.occupied_unit, 0) as occupied_unit, COUNT(DISTINCT maintainers.id) as total_maintainers,properties.*,property_details.address')
            ->groupBy('properties.id')
            ->orderBy('properties.id')
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();
        return $data?->makeHidden(['updated_at', 'created_at', 'deleted_at']);
    }

    public function getById($id)
    {
        return Property::where('owner_user_id', getOwnerUserId())->findOrFail($id);
    }

    public function getDetailsById($id)
    {
        $tenantPropertySummarySql = "(select assignment_per_tenant.property_id,
            COUNT(DISTINCT assignment_per_tenant.tenant_id) as total_tenant,
            COUNT(DISTINCT assignment_per_tenant.unit_id) as occupied_unit,
            AVG(assignment_per_tenant.general_rent) as avg_general_rent,
            SUM(assignment_per_tenant.security_deposit) as total_security_deposit,
            SUM(assignment_per_tenant.late_fee) as total_late_fee
            from (
                select tenant_unit_assignments.property_id, tenant_unit_assignments.unit_id, tenants.id as tenant_id,
                    tenants.general_rent, tenants.security_deposit, tenants.late_fee
                from tenant_unit_assignments
                join tenants on tenants.id = tenant_unit_assignments.tenant_id
                join users on users.id = tenants.user_id
                where users.deleted_at IS NULL and tenants.status = " . TENANT_STATUS_ACTIVE . " and tenants.owner_user_id = " . getOwnerUserId() . "
                group by tenant_unit_assignments.property_id, tenant_unit_assignments.unit_id, tenants.id, tenants.general_rent, tenants.security_deposit, tenants.late_fee
            ) as assignment_per_tenant
            group by assignment_per_tenant.property_id) as tenant_property_summary";

        $data = Property::query()
            ->leftJoin('property_details', 'properties.id', '=', 'property_details.property_id')
            ->leftJoin('users', function ($q) {
                $q->on('properties.maintainer_id', '=', 'users.id')->whereNull('users.deleted_at');
            })
            ->leftJoin(DB::raw($tenantPropertySummarySql), 'tenant_property_summary.property_id', '=', 'properties.id')
            ->selectRaw('GREATEST(properties.number_of_unit - COALESCE(tenant_property_summary.occupied_unit, 0), 0) as available_unit,
             COALESCE(tenant_property_summary.total_tenant, 0) as total_tenant,
             tenant_property_summary.avg_general_rent as avg_general_rent,
             COALESCE(tenant_property_summary.total_security_deposit, 0) as total_security_deposit,
             COALESCE(tenant_property_summary.total_late_fee, 0) as total_late_fee,properties.*,
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
        return $data?->makeHidden(['updated_at', 'created_at', 'deleted_at']);
    }

    public function getByType($type)
    {
        $occupiedSummarySql = "(select tenant_unit_assignments.property_id, COUNT(DISTINCT tenant_unit_assignments.unit_id) as occupied_unit
            from tenant_unit_assignments
            join tenants on tenants.id = tenant_unit_assignments.tenant_id
            join users on users.id = tenants.user_id
            where users.deleted_at IS NULL and tenants.status = " . TENANT_STATUS_ACTIVE . " and tenants.owner_user_id = " . getOwnerUserId() . "
            group by tenant_unit_assignments.property_id) as occupied_summary";

        return Property::query()
            ->leftJoin(DB::raw($occupiedSummarySql), 'occupied_summary.property_id', '=', 'properties.id')
            ->selectRaw('GREATEST(properties.number_of_unit - COALESCE(occupied_summary.occupied_unit, 0), 0) as available_unit,properties.*')
            ->where('properties.property_type', $type)
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();
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
        $occupiedSummarySql = "(select tenant_unit_assignments.property_id, COUNT(DISTINCT tenant_unit_assignments.unit_id) as occupied_unit
            from tenant_unit_assignments
            join tenants on tenants.id = tenant_unit_assignments.tenant_id
            join users on users.id = tenants.user_id
            where users.deleted_at IS NULL and tenants.status = " . TENANT_STATUS_ACTIVE . " and tenants.owner_user_id = " . getOwnerUserId() . "
            group by tenant_unit_assignments.property_id) as occupied_summary";

        $properties = Property::query()
            ->leftJoin(DB::raw($occupiedSummarySql), 'occupied_summary.property_id', '=', 'properties.id')
            ->selectRaw('GREATEST(properties.number_of_unit - COALESCE(occupied_summary.occupied_unit, 0), 0) as available_unit,properties.*')
            ->where('properties.property_type', $type)
            ->where('properties.owner_user_id', getOwnerUserId());

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
            $propertyUnits = PropertyUnit::query()
                ->select('id', 'unit_name as name', 'general_rent', 'security_deposit', 'late_fee', 'security_deposit_type', 'late_fee_type', 'incident_receipt', 'rent_type', 'monthly_due_day', 'yearly_due_day')
                ->where('property_id', $id)
                ->get();

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
                    $property_unit->max_occupancy = $request->single['max_occupancy'][$i] ?? null;
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
                    $property_unit->max_occupancy = $request->multiple['max_occupancy'][$i] ?? null;
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
            }
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
                $response['property'] = Property::where('owner_user_id', getOwnerUserId())->findOrFail($request->property_id);
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
            $response['property'] = Property::where('owner_user_id', getOwnerUserId())->findOrFail($request->property_id);
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
            $response['property'] = Property::where('owner_user_id', getOwnerUserId())->findOrFail($request->property_id);
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
            $response['property'] = Property::where('owner_user_id', getOwnerUserId())->findOrFail($request->property_id);
            $response['propertyUnits'] = PropertyUnit::where('property_id', $response['property']->id)->get();
            $response['propertyUnitIds'] = PropertyUnit::where('property_id', $response['property']->id)->pluck('id')->toArray();
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
            $activeAssignmentExists = TenantUnitAssignment::query()
                ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
                ->where('tenant_unit_assignments.property_id', $id)
                ->where('tenants.status', TENANT_STATUS_ACTIVE)
                ->exists();
            if ($activeAssignmentExists) {
                throw new Exception('Tenant Available! You can\'t delete');
            }
            $property = Property::where('owner_user_id', getOwnerUserId())->findOrFail($id);
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
        $activeTenantSummary = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->where('tenants.status', TENANT_STATUS_ACTIVE)
            ->where('tenants.owner_user_id', getOwnerUserId())
            ->whereNull('users.deleted_at')
            ->selectRaw("tenant_unit_assignments.unit_id, COUNT(DISTINCT tenant_unit_assignments.tenant_id) as active_tenant_count, GROUP_CONCAT(DISTINCT CONCAT(users.first_name, ' ', users.last_name) ORDER BY users.first_name SEPARATOR ', ') as active_tenant_names, MIN(tenants.id) as first_tenant_id")
            ->groupBy('tenant_unit_assignments.unit_id');

        $propertyUnits = PropertyUnit::query()
            ->leftJoinSub($activeTenantSummary, 'tenant_summary', function ($join) {
                $join->on('property_units.id', '=', 'tenant_summary.unit_id');
            })
            ->leftJoin('file_managers', ['property_units.id' => 'file_managers.origin_id', 'file_managers.origin_type' => (DB::raw("'App\\\Models\\\PropertyUnit'"))])
            ->select(
                'property_units.*',
                'file_managers.file_name',
                'file_managers.folder_name',
                'tenant_summary.active_tenant_count',
                'tenant_summary.active_tenant_names',
                'tenant_summary.first_tenant_id'
            )
            ->where('property_units.property_id', $id)
            ->get();
        return $this->success($propertyUnits);
    }

    public function unitDelete($id)
    {
        try {
            $activeAssignmentExists = TenantUnitAssignment::query()
                ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
                ->where('tenant_unit_assignments.unit_id', $id)
                ->where('tenants.status', TENANT_STATUS_ACTIVE)
                ->exists();
            if ($activeAssignmentExists) {
                throw new Exception('Tenant Available! You can\'t delete');
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
        $occupiedSummarySql = "(select tenant_unit_assignments.property_id, COUNT(DISTINCT tenant_unit_assignments.unit_id) as occupied_unit
            from tenant_unit_assignments
            join tenants on tenants.id = tenant_unit_assignments.tenant_id
            join users on users.id = tenants.user_id
            where users.deleted_at IS NULL and tenants.status = " . TENANT_STATUS_ACTIVE . " and tenants.owner_user_id = " . getOwnerUserId() . "
            group by tenant_unit_assignments.property_id) as occupied_summary";

        return Property::query()
            ->leftJoin(DB::raw($occupiedSummarySql), 'occupied_summary.property_id', '=', 'properties.id')
            ->selectRaw('GREATEST(properties.number_of_unit - COALESCE(occupied_summary.occupied_unit, 0), 0) as available_unit,properties.*')
            ->where('properties.property_type', $type)
            ->where('properties.name', 'LIKE', "%{$searchItem}%")
            ->where('properties.owner_user_id', getOwnerUserId())
            ->get();
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

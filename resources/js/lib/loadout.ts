// Dialog defaults of the loadout page. The active type filter pre-fills NEW categories only: an existing category
// (also an all-types one, vehicle_type null) keeps its own value.
export function loadoutDialogDefaults(
    category: { title: string; compartment: number; vehicle_type: string | null; sort_order: number } | null,
    filter: string,
) {
    return {
        title: category?.title ?? '',
        compartment: category?.compartment ?? 0,
        vehicle_type: category ? (category.vehicle_type ?? '') : filter,
        sort_order: category?.sort_order ?? 0,
    };
}

<?php
require_once __DIR__ . '/config.php';

// Initialize search variables
$search_origin = isset($_GET['origin']) ? trim($_GET['origin']) : '';
$search_dest = isset($_GET['destination']) ? trim($_GET['destination']) : '';
$search_origin_lat = isset($_GET['origin_lat']) && $_GET['origin_lat'] !== '' ? floatval($_GET['origin_lat']) : null;
$search_origin_lng = isset($_GET['origin_lng']) && $_GET['origin_lng'] !== '' ? floatval($_GET['origin_lng']) : null;
$search_dest_lat = isset($_GET['dest_lat']) && $_GET['dest_lat'] !== '' ? floatval($_GET['dest_lat']) : null;
$search_dest_lng = isset($_GET['dest_lng']) && $_GET['dest_lng'] !== '' ? floatval($_GET['dest_lng']) : null;

// Build select fields (including distance calculation if coords are provided)
$select_fields = "t.*, CONCAT(u.first_name, ' ', u.last_name) as driver_name, v.brand, v.model, v.plate_number, v.capacity,
                 (SELECT AVG(rating) FROM reviews WHERE driver_id = u.id) AS driver_rating";

if ($search_origin_lat !== null && $search_origin_lng !== null) {
    $select_fields .= ", ( 6371 * acos( cos( radians(?) ) * cos( radians( t.origin_lat ) ) * cos( radians( t.origin_lng ) - radians(?) ) + sin( radians(?) ) * sin( radians( t.origin_lat ) ) ) ) AS origin_distance";
} else {
    $select_fields .= ", 0 AS origin_distance";
}

if ($search_dest_lat !== null && $search_dest_lng !== null) {
    $select_fields .= ", ( 6371 * acos( cos( radians(?) ) * cos( radians( t.destination_lat ) ) * cos( radians( t.destination_lng ) - radians(?) ) + sin( radians(?) ) * sin( radians( t.destination_lat ) ) ) ) AS dest_distance";
} else {
    $select_fields .= ", 0 AS dest_distance";
}

$query = "SELECT $select_fields 
          FROM trips t 
          JOIN users u ON t.driver_id = u.id 
          JOIN vehicles v ON u.id = v.driver_id 
          WHERE t.status = 'active' AND t.departure_time >= NOW()";

$params = [];
$having_clauses = [];

// Parameter binding must match the exact order of ? marks.
// First: origin_distance select fields
if ($search_origin_lat !== null && $search_origin_lng !== null) {
    $params[] = $search_origin_lat;
    $params[] = $search_origin_lng;
    $params[] = $search_origin_lat;
}
// Second: dest_distance select fields
if ($search_dest_lat !== null && $search_dest_lng !== null) {
    $params[] = $search_dest_lat;
    $params[] = $search_dest_lng;
    $params[] = $search_dest_lat;
}

// Add WHERE or HAVING filters
if ($search_origin_lat !== null && $search_origin_lng !== null) {
    $having_clauses[] = "origin_distance <= 20";
} elseif (!empty($search_origin)) {
    $query .= " AND t.origin LIKE ?";
    $params[] = '%' . $search_origin . '%';
}

if ($search_dest_lat !== null && $search_dest_lng !== null) {
    $having_clauses[] = "dest_distance <= 20";
} elseif (!empty($search_dest)) {
    $query .= " AND t.destination LIKE ?";
    $params[] = '%' . $search_dest . '%';
}

if (!empty($having_clauses)) {
    $query .= " HAVING " . implode(" AND ", $having_clauses);
}

$query .= " ORDER BY t.departure_time ASC";

$trips = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $trips = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table trips may not exist yet
    }
}

// Convert trips to JSON for Leaflet map markers
$map_trips_json = json_encode(array_map(function($trip) {
    return [
        'id' => $trip['id'],
        'origin' => $trip['origin'],
        'destination' => $trip['destination'],
        'origin_lat' => floatval($trip['origin_lat']),
        'origin_lng' => floatval($trip['origin_lng']),
        'destination_lat' => floatval($trip['destination_lat']),
        'destination_lng' => floatval($trip['destination_lng']),
        'price' => floatval($trip['price_total']),
        'departure_time' => date('M d, Y h:i A', strtotime($trip['departure_time'])),
        'vehicle' => $trip['brand'] . ' ' . $trip['model'],
        'driver' => $trip['driver_name']
    ];
}, array_filter($trips, function($t) {
    return !empty($t['origin_lat']) && !empty($t['origin_lng']);
})));

include_once __DIR__ . '/includes/header.php';
?>

<!-- Leaflet Map CSS CDN -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

<div class="container my-3">
    <!-- Hero Section -->
    <div class="row align-items-center mb-5 py-4 rounded-4" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(15, 23, 42, 0.2) 100%); border: 1px solid rgba(99, 102, 241, 0.15);">
        <div class="col-lg-7 text-center text-lg-start ps-lg-5">
            <h1 class="display-5 fw-bold text-white mb-3">Premium Private Rides,<br><span style="background: var(--primary-gradient); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">Completely Chauffeur-Driven</span></h1>
            <p class="text-muted fs-5 mb-4">Forget colorum carpools. Rent a dedicated private vehicle with a verified driver for your provincial trips, airport transfers, and out-of-town journeys.</p>
        </div>
        <div class="col-lg-5 text-center pe-lg-5 d-none d-lg-block">
            <div class="py-5 bg-card rounded-4 p-4 text-center border border-secondary shadow-lg">
                <i class="bi bi-shield-check text-success display-2"></i>
                <h4 class="fw-bold mt-3 text-white">100% Verified Drivers</h4>
                <p class="text-muted small">Every vehicle is fully vetted via OR/CR and official license verification. Secure platform payments with no hidden charges.</p>
            </div>
        </div>
    </div>

    <!-- Search Section -->
    <div class="row mb-4" style="position: relative; z-index: 1050;">
        <div class="col-12">
            <div class="card card-custom p-4 shadow" style="overflow: visible !important;">
                <h4 class="fw-bold mb-3 text-white"><i class="bi bi-compass me-2 text-primary"></i>Find Private Charters</h4>
                <form action="index.php" method="GET" class="row g-3">
                    <div class="col-md-5" style="z-index: 1000;">
                        <label for="origin" class="form-label text-muted small">From (Pickup Origin)</label>
                        <div class="input-group position-relative">
                            <span class="input-group-text bg-transparent border-secondary text-muted"><i class="bi bi-geo-alt-fill"></i></span>
                            <input type="text" name="origin" id="originInput" class="form-control form-control-custom" placeholder="e.g. Angeles, Pampanga" value="<?php echo htmlspecialchars($search_origin); ?>" autocomplete="off">
                            <ul id="originSuggestions" class="list-group position-absolute w-100 shadow m-0 p-0" style="top:100%; left:0; z-index: 9999; display:none; max-height: 200px; overflow-y: auto; border-radius: 0 0 8px 8px; border: 1px solid #495057;"></ul>
                            <input type="hidden" name="origin_lat" id="origin_lat" value="<?php echo htmlspecialchars($search_origin_lat ?? ''); ?>">
                            <input type="hidden" name="origin_lng" id="origin_lng" value="<?php echo htmlspecialchars($search_origin_lng ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-5" style="z-index: 900;">
                        <label for="destination" class="form-label text-muted small">To (Dropoff Destination)</label>
                        <div class="input-group position-relative">
                            <span class="input-group-text bg-transparent border-secondary text-muted"><i class="bi bi-pin-map-fill"></i></span>
                            <input type="text" name="destination" id="destInput" class="form-control form-control-custom" placeholder="e.g. NAIA Terminal 3, Manila" value="<?php echo htmlspecialchars($search_dest); ?>" autocomplete="off">
                            <ul id="destSuggestions" class="list-group position-absolute w-100 shadow m-0 p-0" style="top:100%; left:0; z-index: 9999; display:none; max-height: 200px; overflow-y: auto; border-radius: 0 0 8px 8px; border: 1px solid #495057;"></ul>
                            <input type="hidden" name="dest_lat" id="dest_lat" value="<?php echo htmlspecialchars($search_dest_lat ?? ''); ?>">
                            <input type="hidden" name="dest_lng" id="dest_lng" value="<?php echo htmlspecialchars($search_dest_lng ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-gradient-primary w-100 py-2.5">
                            <i class="bi bi-search me-2"></i>Search
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Interactive Map Section -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="card card-custom p-3 shadow">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-white"><i class="bi bi-map me-2 text-primary"></i>Interactive Route Explorer</h5>
                    <span class="badge bg-secondary rounded-pill small px-2.5 py-1.5"><i class="bi bi-circle-fill text-success me-1 animate-pulse" style="font-size:0.6rem;"></i>Showing Live Active Pickups</span>
                </div>
                <!-- Leaflet Map Container -->
                <div id="map"></div>
            </div>
        </div>
    </div>

    <!-- Available Trips Section -->
    <div class="row">
        <div class="col-12">
            <h4 class="fw-bold mb-4 text-white"><i class="bi bi-calendar2-check me-2 text-primary"></i>Available Charters (<?php echo count($trips); ?>)</h4>
        </div>
        
        <?php if (empty($trips)): ?>
            <div class="col-12">
                <div class="card card-custom p-5 text-center">
                    <i class="bi bi-car-front text-muted display-4 mb-3"></i>
                    <h5 class="text-white">No Available Private Rides Found</h5>
                    <p class="text-muted mb-0">Try searching for other pickup or dropoff locations, or check back later.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($trips as $trip): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card card-custom h-100 shadow d-flex flex-column justify-content-between p-3">
                        <div>
                            <!-- Header & Price -->
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="badge bg-primary text-white rounded-pill px-2.5 py-1.5">Whole Vehicle</span>
                                <h3 class="fw-bold text-success mb-0"><?php echo format_peso($trip['price_total']); ?></h3>
                            </div>
                            
                            <!-- Origin -> Dest -->
                            <div class="mb-3">
                                <h5 class="fw-bold text-white mb-1"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($trip['origin']); ?></h5>
                                <?php if (isset($trip['origin_distance']) && $trip['origin_distance'] > 0): ?>
                                    <small class="text-warning d-block mb-1 ms-4"><i class="bi bi-info-circle me-1"></i>Pickup is <?php echo round($trip['origin_distance'], 1); ?> km from your search</small>
                                <?php endif; ?>
                                
                                <h5 class="fw-bold text-white mb-1"><i class="bi bi-pin-map-fill text-primary me-1"></i><?php echo htmlspecialchars($trip['destination']); ?></h5>
                                <?php if (isset($trip['dest_distance']) && $trip['dest_distance'] > 0): ?>
                                    <small class="text-warning d-block ms-4"><i class="bi bi-info-circle me-1"></i>Dropoff is <?php echo round($trip['dest_distance'], 1); ?> km from your search</small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Trip Details -->
                            <div class="bg-dark bg-opacity-25 rounded-3 p-3 mb-3 border border-secondary border-opacity-50">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Departure Date</small>
                                        <span class="text-white small fw-medium"><i class="bi bi-calendar-event me-1"></i><?php echo date('M d, Y', strtotime($trip['departure_time'])); ?></span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Time</small>
                                        <span class="text-white small fw-medium"><i class="bi bi-clock me-1"></i><?php echo date('h:i A', strtotime($trip['departure_time'])); ?></span>
                                    </div>
                                </div>
                                <hr class="my-2 border-secondary opacity-50">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Vehicle</small>
                                        <span class="text-white small fw-medium"><i class="bi bi-car-front-fill me-1 text-info"></i><?php echo htmlspecialchars($trip['brand'] . ' ' . $trip['model']); ?></span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Vehicle Type</small>
                                        <span class="text-white small fw-medium"><i class="bi bi-people-fill me-1 text-warning"></i><?php echo htmlspecialchars($trip['capacity']); ?> Seater <span class="text-muted" style="font-size:0.75rem;">(incl. driver)</span></span>
                                    </div>
                                </div>
                                <?php if (!empty($trip['description'])): ?>
                                <hr class="my-2 border-secondary opacity-50">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <small class="text-muted d-block"><i class="bi bi-info-circle me-1"></i>Driver's Note</small>
                                        <span class="text-light small fst-italic">"<?php echo nl2br(htmlspecialchars($trip['description'])); ?>"</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Driver Details & Button -->
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3 text-muted small">
                                <div>
                                    <span class="d-block">Driver: <strong><?php echo htmlspecialchars($trip['driver_name']); ?></strong></span>
                                    <span class="text-warning" style="font-size:0.75rem;">
                                        <?php if (!empty($trip['driver_rating'])): ?>
                                            <i class="bi bi-star-fill me-1"></i><?php echo number_format($trip['driver_rating'], 1); ?> / 5.0
                                        <?php else: ?>
                                            <i class="bi bi-star me-1"></i>New Driver
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <span class="badge-verified"><i class="bi bi-shield-check me-1"></i>Verified</span>
                            </div>
                            
                            <a href="<?php echo BASE_URL; ?>client/book_trip.php?trip_id=<?php echo $trip['id']; ?>" class="btn btn-gradient-primary w-100 py-2.5">
                                <i class="bi bi-wallet2 me-2"></i>Book & Pay Full Fare
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Leaflet Map JS CDN -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialize Map centered in Central Luzon / Manila coordinates
    var map = L.map('map').setView([14.8964, 120.7303], 9); 

    // Add free OpenStreetMap tile layers
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // 2. Parse active trips JSON from PHP
    var activeTrips = <?php echo $map_trips_json; ?>;
    
    // Fit bounds holder to automatically zoom to markers
    var markerBounds = [];

    // 3. Render markers
    activeTrips.forEach(function(trip) {
        if(trip.origin_lat && trip.origin_lng) {
            // Create red marker for Origin
            var marker = L.marker([trip.origin_lat, trip.origin_lng]).addTo(map);
            markerBounds.push([trip.origin_lat, trip.origin_lng]);

            // Draw polyline connecting pickup and dropoff if dropoff exists
            if (trip.destination_lat && trip.destination_lng) {
                var path = L.polyline([
                    [trip.origin_lat, trip.origin_lng],
                    [trip.destination_lat, trip.destination_lng]
                ], {
                    color: '#6366f1',
                    weight: 3,
                    dashArray: '5, 10',
                    opacity: 0.7
                }).addTo(map);
                markerBounds.push([trip.destination_lat, trip.destination_lng]);
            }
            
            // Format price
            var formatter = new Intl.NumberFormat('en-PH', {
                style: 'currency',
                currency: 'PHP'
            });
            var priceFormatted = formatter.format(trip.price);

            // Popups when clicked
            marker.bindPopup(`
                <div style="font-family:'Outfit', sans-serif; color:#0f172a; min-width: 180px;">
                    <h6 style="margin:0 0 5px 0; font-weight:700; color:#6366f1;">Private Charter</h6>
                    <p style="margin:0 0 3px 0; font-size:0.85rem;"><strong>From:</strong> ${trip.origin}</p>
                    <p style="margin:0 0 6px 0; font-size:0.85rem;"><strong>To:</strong> ${trip.destination}</p>
                    <p style="margin:0 0 3px 0; font-size:0.8rem; color:#64748b;"><i class="bi bi-calendar"></i> ${trip.departure_time}</p>
                    <p style="margin:0 0 8px 0; font-size:0.8rem; color:#64748b;"><i class="bi bi-car-front"></i> ${trip.vehicle}</p>
                    <h5 style="margin:0 0 8px 0; font-weight:700; color:#10b981;">${priceFormatted}</h5>
                    <a href="${window.location.origin}/sakayph/client/book_trip.php?trip_id=${trip.id}" 
                       style="display:block; text-align:center; background:#6366f1; color:white; padding:6px; border-radius:6px; text-decoration:none; font-size:0.8rem; font-weight:600;">
                       Book Ride
                    </a>
                </div>
            `);
        }
    });

    // 4. Auto adjust map zoom to fit all markers if present
    if (markerBounds.length > 0) {
        map.fitBounds(markerBounds, { padding: [50, 50] });
    }
});
</script>

<style>
    /* Suggestions Dropdown Styling */
    .suggestion-item {
        cursor: pointer;
        background-color: #2b3035;
        color: #fff;
        border-color: #495057;
        font-size: 0.9rem;
    }
    .suggestion-item:hover {
        background-color: #343a40;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Clear lat/lng if user manually edits input (so it falls back to text search if they don't click suggestion)
    document.getElementById('originInput').addEventListener('input', function() {
        document.getElementById('origin_lat').value = '';
        document.getElementById('origin_lng').value = '';
    });
    document.getElementById('destInput').addEventListener('input', function() {
        document.getElementById('dest_lat').value = '';
        document.getElementById('dest_lng').value = '';
    });

    // Shared setup for autocomplete (same as post_trip.php logic)
    function setupAutocomplete(inputId, suggsId, latId, lngId) {
        const input = document.getElementById(inputId);
        const suggs = document.getElementById(suggsId);
        let timeout = null;

        input.addEventListener('input', function() {
            clearTimeout(timeout);
            const query = this.value.trim();
            if (query.length < 3) {
                suggs.style.display = 'none';
                return;
            }

            // Debounce 500ms
            timeout = setTimeout(() => {
                fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&countrycodes=ph`)
                    .then(res => res.json())
                    .then(data => {
                        suggs.innerHTML = '';
                        if (data.length > 0) {
                            data.slice(0, 5).forEach(item => {
                                const li = document.createElement('li');
                                li.className = 'list-group-item suggestion-item';
                                
                                // Clean up display name to mostly show city/province for cleaner search
                                var addressParts = item.display_name.split(',');
                                var shortAddress = addressParts.slice(0, 3).join(',').trim();
                                
                                li.innerText = shortAddress;
                                li.addEventListener('click', () => {
                                    input.value = shortAddress;
                                    document.getElementById(latId).value = parseFloat(item.lat);
                                    document.getElementById(lngId).value = parseFloat(item.lon);
                                    suggs.style.display = 'none';
                                });
                                suggs.appendChild(li);
                            });
                            suggs.style.display = 'block';
                        }
                    });
            }, 500);
        });

        // Hide on click outside
        document.addEventListener('click', (e) => {
            if (e.target !== input && e.target !== suggs) {
                suggs.style.display = 'none';
            }
        });
    }

    setupAutocomplete('originInput', 'originSuggestions', 'origin_lat', 'origin_lng');
    setupAutocomplete('destInput', 'destSuggestions', 'dest_lat', 'dest_lng');
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

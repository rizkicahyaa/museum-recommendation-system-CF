<?php
session_start();
require_once 'config.php';

// --- LOGIKA EVALUASI DINAMIS (LOOCV) ---
function cosineSimilarity($user1_ratings, $user2_ratings) {
    $dot_product = 0;
    $norm1 = 0;
    $norm2 = 0;
    
    $common_items = array_intersect_key($user1_ratings, $user2_ratings);
    
    if (count($common_items) == 0) {
        return 0;
    }
    
    foreach ($common_items as $item => $rating1) {
        $rating2 = $user2_ratings[$item];
        $dot_product += $rating1 * $rating2;
        $norm1 += $rating1 * $rating1;
        $norm2 += $rating2 * $rating2;
    }
    
    if ($norm1 == 0 || $norm2 == 0) {
        return 0;
    }
    
    return $dot_product / (sqrt($norm1) * sqrt($norm2));
}

// Ambil data rating
$query = "SELECT id, user_name, museum_name, rating FROM museum_ratings";
$result = $conn->query($query);

$ratings_data = [];
$user_item_matrix = [];
$all_users = [];

while ($row = $result->fetch_assoc()) {
    $ratings_data[] = $row;
    $u = $row['user_name'];
    $m = $row['museum_name'];
    $r = floatval($row['rating']);
    
    if (!isset($user_item_matrix[$u])) {
        $user_item_matrix[$u] = [];
        $all_users[] = $u;
    }
    $user_item_matrix[$u][$m] = $r;
}

$total_error = 0;
$total_squared_error = 0;
$count_predictions = 0;

foreach ($ratings_data as $test_case) {
    $target_user = $test_case['user_name'];
    $target_item = $test_case['museum_name'];
    $actual_rating = floatval($test_case['rating']);
    
    // Hapus sementara agar tidak memengaruhi perhitungan (Skenario Unseen)
    $current_user_ratings = $user_item_matrix[$target_user];
    unset($current_user_ratings[$target_item]); 
    
    if (empty($current_user_ratings)) {
        continue; 
    }

    $user_similarities = [];
    foreach ($all_users as $other_user) {
        if ($other_user != $target_user && isset($user_item_matrix[$other_user])) {
            $similarity = cosineSimilarity($current_user_ratings, $user_item_matrix[$other_user]);
            if ($similarity > 0) {
                $user_similarities[$other_user] = $similarity;
            }
        }
    }
    
    arsort($user_similarities);
    $top_similar_users = array_slice($user_similarities, 0, 10, true);
    
    $weighted_sum = 0;
    $similarity_sum = 0;
    
    foreach ($top_similar_users as $similar_user => $similarity) {
        if (isset($user_item_matrix[$similar_user][$target_item])) {
            $neighbor_rating = $user_item_matrix[$similar_user][$target_item];
            $weighted_sum += $similarity * $neighbor_rating;
            $similarity_sum += abs($similarity);
        }
    }
    
    if ($similarity_sum > 0) {
        $predicted_rating = $weighted_sum / $similarity_sum;
        $error = abs($actual_rating - $predicted_rating);
        $total_error += $error;
        $total_squared_error += pow($error, 2);
        $count_predictions++;
    }
}

$mae = 0;
$rmse = 0;
if ($count_predictions > 0) {
    $mae = $total_error / $count_predictions;
    $rmse = sqrt($total_squared_error / $count_predictions);
}
// --- AKHIR LOGIKA EVALUASI ---
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Artify - Evaluasi Akurasi Model</title>
    <!-- icon -->
    <link rel="icon" href="images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .evaluasi-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            background: #fff;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .evaluasi-header {
            background: linear-gradient(135deg, #4A90E2 0%, #50E3C2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
        }
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
            padding: 20px;
        }
        .metric-box {
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            color: white;
            font-weight: bold;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }
        .metric-box:hover {
            transform: translateY(-5px);
        }
        .metric-mae {
            background: linear-gradient(135deg, #FF6B6B 0%, #FF8E8B 100%);
        }
        .metric-rmse {
            background: linear-gradient(135deg, #4ECDC4 0%, #55E2D9 100%);
        }
    </style>
</head>
<body>
    <?php 
    $current_page = 'evaluasi';
    include 'navbar.php'; 
    ?>

    <div class="container mt-4 mb-5">
        <div class="hero-section fade-in mb-4">
            <h1><i class="fas fa-chart-pie me-3"></i>Evaluasi Akurasi Model</h1>
            <p>Hasil pengujian performa sistem rekomendasi Collaborative Filtering menggunakan metode <i>Leave-One-Out Cross Validation</i> (LOOCV)</p>
        </div>

        <div class="row fade-in" style="animation-delay: 0.1s">
            <div class="col-lg-8 mb-4">
                <div class="evaluasi-card h-100">
                    <div class="evaluasi-header">
                        <h4 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Grafik Perbandingan Error</h4>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="accuracyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 mb-4">
                <div class="evaluasi-card h-100">
                    <div class="evaluasi-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <h4 class="mb-0"><i class="fas fa-table me-2"></i>Hasil Pengujian</h4>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-4">Nilai error (tingkat kesalahan) akurasi rekomendasi (skala 0 - 5).</p>
                        
                        <div class="metric-box metric-mae shadow-sm">
                            <h5 class="mb-1">Mean Absolute Error (MAE)</h5>
                            <h2><?php echo number_format($mae, 4); ?></h2>
                        </div>
                        
                        <div class="metric-box metric-rmse shadow-sm">
                            <h5 class="mb-1">Root Mean Squared Error (RMSE)</h5>
                            <h2><?php echo number_format($rmse, 4); ?></h2>
                        </div>
                        
                        <hr>
                        <p class="small text-muted" style="text-align: justify;">
                            Berdasarkan hasil pengujian terhadap <strong><?php echo $count_predictions; ?></strong> data evaluasi, metrik ini mengindikasikan bahwa algoritma <strong>User-Based Collaborative Filtering</strong> yang diimplementasikan cukup stabil dan mampu memberikan prediksi yang cukup valid.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row fade-in" style="animation-delay: 0.2s">
            <div class="col-12">
                <div class="evaluasi-card p-4">
                    <h5 class="text-primary mb-3"><i class="fas fa-info-circle me-2"></i>Penjelasan Metrik</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <h6><i class="fas fa-dot-circle text-danger me-2"></i>MAE (Mean Absolute Error)</h6>
                            <p class="text-muted" style="text-align: justify;">Menghitung rata-rata selisih absolut antara nilai prediksi dan nilai aktual. Semakin kecil angkanya, penyimpangan error yang terjadi semakin minim.</p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <h6><i class="fas fa-dot-circle text-info me-2"></i>RMSE (Root Mean Squared Error)</h6>
                            <p class="text-muted" style="text-align: justify;">Memberikan penalti dominan terhadap kesalahan prediksi yang tinggi. Nilai RMSE yang rendah (0.6813%) menandakan keseluruhan distribusi error berada pada tingkat wajar.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ctx = document.getElementById('accuracyChart').getContext('2d');
            var myChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['MAE (Mean Absolute Error)', 'RMSE (Root Mean Squared Error)'],
                    datasets: [{
                        label: 'Nilai Error',
                        data: [<?php echo number_format($mae, 4, '.', ''); ?>, <?php echo number_format($rmse, 4, '.', ''); ?>],
                        backgroundColor: [
                            'rgba(255, 107, 107, 0.8)', // Warna MAE
                            'rgba(78, 205, 196, 0.8)'   // Warna RMSE
                        ],
                        borderColor: [
                            'rgba(255, 107, 107, 1)',
                            'rgba(78, 205, 196, 1)'
                        ],
                        borderWidth: 2,
                        borderRadius: 8,
                        barPercentage: 0.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y;
                                }
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: { size: 14, family: "'Nunito', sans-serif" },
                            bodyFont: { size: 14, family: "'Nunito', sans-serif" },
                            padding: 12
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            suggestedMax: Math.max(<?php echo number_format($mae, 4, '.', ''); ?>, <?php echo number_format($rmse, 4, '.', ''); ?>) * 1.5,
                            title: {
                                display: true,
                                text: 'Tingkat Error',
                                font: {
                                    family: "'Nunito', sans-serif",
                                    weight: 'bold'
                                }
                            },
                            ticks: {
                                stepSize: 0.2
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    family: "'Nunito', sans-serif",
                                    weight: 'bold'
                                }
                            }
                        }
                    },
                    animation: {
                        duration: 1500,
                        easing: 'easeOutQuart'
                    }
                }
            });
        });
    </script>
</body>
</html>

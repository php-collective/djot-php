#!/bin/bash
#
# Djot-PHP Complete Benchmark Suite Runner
#
# Usage:
#   ./run-all.sh [--quick] [--full] [--compare]
#

set -e
cd "$(dirname "$0")"

ITERATIONS=50
WARMUP=10
QUICK=false
COMPARE=false
FULL=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --quick)
            QUICK=true
            ITERATIONS=10
            WARMUP=3
            shift
            ;;
        --full)
            FULL=true
            ITERATIONS=100
            WARMUP=20
            shift
            ;;
        --compare)
            COMPARE=true
            shift
            ;;
        --help)
            echo "Djot-PHP Benchmark Suite Runner"
            echo ""
            echo "Usage: ./run-all.sh [options]"
            echo ""
            echo "Options:"
            echo "  --quick     Quick run with fewer iterations (10 iters, 3 warmup)"
            echo "  --full      Full run with more iterations (100 iters, 20 warmup)"
            echo "  --compare   Include cross-language comparison (requires npm, python)"
            echo "  --help      Show this help"
            echo ""
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

echo "========================================"
echo "Djot-PHP Performance Benchmark Suite"
echo "========================================"
echo ""
echo "Configuration:"
echo "  Iterations: $ITERATIONS"
echo "  Warmup: $WARMUP"
echo "  Quick mode: $QUICK"
echo "  Cross-language: $COMPARE"
echo ""

# Create results directory
mkdir -p results

# Run PHP benchmark
echo "----------------------------------------"
echo "1. Running PHP Benchmark"
echo "----------------------------------------"
php benchmark.php --iterations=$ITERATIONS --warmup=$WARMUP
echo ""

# Run memory profiler
echo "----------------------------------------"
echo "2. Running Memory Profiler"
echo "----------------------------------------"
php memory-profile.php
echo ""

# Run stress tests (quick mode only runs a subset)
echo "----------------------------------------"
echo "3. Running Stress Tests"
echo "----------------------------------------"
if [ "$QUICK" = true ]; then
    php stress-test.php --scenario=deep_nesting
    php stress-test.php --scenario=pathological
else
    php stress-test.php
fi
echo ""

# Cross-language comparison (optional)
if [ "$COMPARE" = true ]; then
    echo "----------------------------------------"
    echo "4. Cross-Language Comparison"
    echo "----------------------------------------"

    # Check for npm
    if command -v npm &> /dev/null; then
        # Install JS dependencies if needed
        if [ ! -d "node_modules" ]; then
            echo "Installing npm dependencies..."
            npm install
        fi

        echo "Running JavaScript benchmark..."
        node benchmark-js.mjs --iterations=$ITERATIONS --warmup=$WARMUP
    else
        echo "Skipping JavaScript benchmark (npm not found)"
    fi
    echo ""

    # Check for Python
    if command -v python3 &> /dev/null; then
        echo "Running Python benchmark..."
        python3 benchmark-python.py --iterations=$ITERATIONS --warmup=$WARMUP 2>/dev/null || echo "Python benchmark failed (missing dependencies?)"
    else
        echo "Skipping Python benchmark (python3 not found)"
    fi
    echo ""

    # Run comparison if node is available
    if command -v node &> /dev/null && [ -d "node_modules" ]; then
        echo "Generating comparison report..."
        node compare-languages.mjs --iterations=$ITERATIONS --warmup=$WARMUP
    fi
fi

# Generate JSON results for storage
echo "----------------------------------------"
echo "Saving Results"
echo "----------------------------------------"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
php benchmark.php --iterations=$ITERATIONS --warmup=$WARMUP --json > "results/php-benchmark-$TIMESTAMP.json"
echo "Saved: results/php-benchmark-$TIMESTAMP.json"

if [ "$COMPARE" = true ] && command -v node &> /dev/null && [ -d "node_modules" ]; then
    node benchmark-js.mjs --iterations=$ITERATIONS --warmup=$WARMUP --json > "results/js-benchmark-$TIMESTAMP.json"
    echo "Saved: results/js-benchmark-$TIMESTAMP.json"
fi

echo ""
echo "========================================"
echo "Benchmark Complete!"
echo "========================================"
echo ""
echo "To generate an HTML report:"
echo "  php generate-report.php"
echo ""

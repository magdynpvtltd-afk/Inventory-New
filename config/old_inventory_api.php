<?php
/**
 * MagDyn — Old Inventory API connection config.
 *
 * Points to the api_export_assets.php file deployed on the old
 * inventory server.  The token must match API_TOKEN in that file.
 */
return [
    // Full URL to api_export_assets.php on the old server
    'url'     => 'http://192.168.1.249/inventory/api_export_assets.php',

    // Full URL to tree7-5.php on the old server (BOM tree CSV export)
    'tree_url' => 'http://192.168.1.249/inventory/custom/tree7-5.php',

    // Full URL to api_export_transactions.php on the old server
    'transactions_url' => 'http://192.168.1.249/inventory/api_export_transactions.php',

    // Full URL to api_export_vendors.php on the old server
    // (vendors / contacts / addresses + application users)
    'vendors_url' => 'http://192.168.1.249/inventory/api_export_vendors.php',

    // Shared secret — must match API_TOKEN defined in both export files
    'token'   => 'MAGDYN_IMPORT_SECRET',

    // HTTP timeout in seconds for each API call
    // Use 120s for the BOM import — 200+ root trees take time to traverse.
    'timeout' => 120,

    // Explicit list of root item IDs to import from the old server.
    // These are the auto-detected root assemblies from the debug endpoint.
    // Add or remove IDs as needed. If empty, auto-detection is used.
    'root_ids' => [
        183, 289, 305, 311, 318, 324, 330, 354, 376, 377,
        391, 435, 440, 447, 448, 450, 451, 457, 579, 589,
        599, 641, 657, 666, 699, 700, 701, 702, 723, 726,
        746, 759, 775, 783, 821, 826, 831, 855, 864, 883,
        891, 902, 905, 916, 1075, 1076, 1079, 1080, 1085, 1086,
        1089, 1134, 1150, 1184, 1209, 1218, 1219, 1220, 1221, 1227,
        1228, 1229, 1232, 1252, 1253, 1258, 1267, 1269, 1271, 1273,
        1275, 1277, 1280, 1284, 1287, 1296, 1298, 1300, 1304, 1306,
        1308, 1311, 1313, 1315, 1320, 1340, 1342, 1355, 1357, 1390,
        1392, 1394, 1396, 1398, 1400, 1403, 1411, 1463, 1478, 1480,
        1482, 1484, 1486, 1488, 1490, 1503, 1516, 1521, 1523, 1524,
        1525, 1529, 1532, 1534, 1538, 1540, 1542, 1544, 1588, 1590,
        1591, 1614, 1645, 1647, 1651, 1652, 1653, 1654, 1655, 1671,
        1674, 1675, 1676, 1680, 1681, 1684, 1686, 1688, 1752, 1753,
        1754, 1757, 1758, 1759, 1760, 1761, 1762, 1763, 1796, 1797,
        1804, 1805, 1808, 1809, 1815, 1816, 1817, 1836, 1837, 1841,
        1842, 1844, 1845, 1846, 1847, 1863, 1864, 1865, 1874, 1910,
        1911, 1912, 1913, 1919, 1924, 1925, 1926, 1928, 1929, 1930,
        1931, 1935, 1936, 1937, 1945, 1955, 1956, 1957, 1958, 1959,
        1960, 1961, 1962, 1963, 1964, 1965, 1966, 1967, 1968, 1969,
        1970, 1971, 1973, 1976, 1979, 1980, 1981, 1982, 1984, 1985,
        1986, 1987, 1988, 1989, 1990, 1991, 1992, 1993, 1994, 1996,
        1997, 1998, 1999, 2000, 2001, 2002, 2003, 2004, 2005, 2006,
        2007, 2008, 2009, 2010, 2011, 2012, 2013, 2014, 2015, 2016,
        2017, 2018, 2019, 2020, 2023, 2024, 2025, 2026, 2237, 2238,
        2269, 2271, 2272, 2282, 2283, 2284,
    ],
];

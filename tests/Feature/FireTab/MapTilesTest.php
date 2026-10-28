<?php

test('each gtadb map type ships its z/x/y tiles, the atlas is deliberately absent', function () {
    foreach (['satellite', 'hybrid', 'roadmap', 'terrain'] as $type) {
        foreach (['0/0/0', '5/0/0', '5/31/31'] as $zxy) {
            expect(public_path("assets/map/$type/$zxy.jpg"))->toBeFile();
        }
    }
    expect(public_path('assets/map/tiles'))->not->toBeDirectory();
});

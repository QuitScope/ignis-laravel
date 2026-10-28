<?php

// CORS off: ef_bridge calls server to server, the FiveM NUI talks via postMessage, no browser client
// of another origin exists. The other keys come from the framework defaults.
return [
    'paths' => [],
];

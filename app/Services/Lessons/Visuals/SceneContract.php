<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/**
 * The one definition of a scene description (visuals.v1): used by the prompt, by SceneValidator
 * (enforced in code for every model) and by lesson-scene.js (which draws it).
 */
final class SceneContract
{
    public const SHAPES = ['box', 'sphere', 'cylinder', 'cone', 'capsule', 'torus', 'plane', 'tube', 'arrow', 'text', 'particles'];

    public const PROPS = ['scale', 'x', 'y', 'z', 'opacity', 'colour', 'visible', 'speed', 'count'];

    public const CONTROLS = ['slider', 'toggle', 'buttons'];

    public const MOTIONS = ['none', 'jiggle', 'random', 'flow'];

    public const MAX_PARTS = 160;
    public const MAX_COORD = 12.0;
    public const MAX_LABEL_WORDS = 6;

    public static function guide(): string
    {
        $prefabs = Prefabs::describe();
        return <<<TXT
COORDINATES: y is up. Keep the whole scene inside about -5 to 5 on each axis (never beyond 12). Sizes are in the
same units (a cell is about 6 wide). Angles in "rot" are degrees. Colours are hex like "#2e7d32".

SHAPES (a part is one shape):
- box: "size": [w, h, d]            - sphere: "radius", optional "scale": [sx, sy, sz] for an oval
- cylinder: "radius", "height", optional "open": true (a glass with no lid)
- cone: "radius", "height" (tip points up)       - capsule: "radius", "length" (a rounded rod, along y)
- torus: "radius", "tube", optional "arc" (degrees, 360 = full ring)
- plane: "size": [w, h]             - tube: "points": [[x,y,z], …] (a curved rod through the points), "radius"
- arrow: "from": [x,y,z], "to": [x,y,z], optional "radius"
- text: "text" (1 to 4 words, drawn in the scene), "size"
- particles: "count" (up to 600), "radius", "region": {"pos": [x,y,z], "size": [w,h,d]},
  "motion": none | jiggle | random | flow, "speed", optional "direction": [x,y,z] for flow

EVERY PART: "id" (short, unique, letters/numbers/_), "shape", "pos": [x,y,z], "colour", optional "opacity" (0 to 1;
use 0.15 to 0.4 for see-through shells), optional "rot", "label" (the name a student sees when they tap it, at most
6 words, the book's own words), optional "group" (parts that share a name, e.g. several chloroplasts), optional
"hidden": true (appears later in a state), optional "edges": true (outline a see-through box or glass).

READY-MADE PIECES (use them when they fit; they already carry the textbook labels):
{"prefab": "plant_cell", "id": "pc", "pos": [0,0,0], "scale": 1, "labels": {"wall": "Cell wall"}, "hide": ["mito"]}
Their parts are then called "pc.wall", "pc.vacuole", … (groups too: "pc.chloroplast").
$prefabs

STATES (what the scene shows at each moment):
{"show": [ids], "hide": [ids], "highlight": [ids], "labels": "all" | "none" | [ids],
 "set": {"pc.vacuole.scale": 1.3, "w.speed": 1.5, "o.inside.y": 0.8}, "camera": {"dist": 9, "rx": 20, "ry": -30}, "caption": "short"}
Ids may be part ids or group ids. Settable properties: scale (number or [x,y,z]), x, y, z (moves from the start
position), opacity, colour, visible, speed and count (particles).
Each state starts from the original scene, then applies its changes.

CONTROLS (widgets and explore blocks):
{"id": "water", "type": "slider", "label": "Water outside", "sets": "pc.vacuole.scale", "min": 1, "max": 1.4, "step": 0.05, "value": 1}
{"id": "tonic", "type": "buttons", "label": "Solution", "options": [{"label": "Isotonic", "state": 1}, {"label": "Hypertonic", "state": 2}]}
{"id": "energy", "type": "toggle", "label": "Energy", "sets": "pump.visible", "value": true}
("state" numbers count from 1.)

TASKS (explore and widget blocks): link each written task to the part, group or control it uses:
"tasks": [{"task": 1, "target": "pc.wall"}, {"task": 2, "target": "water"}]
TXT;
    }

    public static function example(): string
    {
        return <<<'JSON'
{"page": "<exact page key>",
 "scenes": [
  {"block": "b2", "mode": "3d",
   "camera": {"dist": 11, "rx": 18, "ry": -25},
   "parts": [
     {"prefab": "plant_cell", "id": "pc", "pos": [0, 0, 0]},
     {"id": "w", "shape": "particles", "count": 60, "radius": 0.06, "colour": "#1565c0", "motion": "random", "speed": 0.4,
      "region": {"pos": [5, 0, 0], "size": [2, 4, 4]}, "label": "Water molecules", "pos": [5, 0, 0]},
     {"id": "in", "shape": "arrow", "from": [4.4, 0, 0], "to": [3.2, 0, 0], "colour": "#d84315", "label": "Water enters", "hidden": true}
   ],
   "states": [
     {"labels": "all", "caption": "A plant cell"},
     {"highlight": ["pc.vacuole"], "show": ["in"], "set": {"pc.vacuole.scale": 1.25}, "caption": "Water enters by osmosis"}
   ],
   "controls": [],
   "tasks": []}
 ]}
JSON;
    }
}

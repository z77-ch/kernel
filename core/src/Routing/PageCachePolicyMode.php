<?php

namespace Z77\Core\Routing;

enum PageCachePolicyMode
{
    /** newObject, Controller has to generate*/
    case NewPage;

    /** load from server cache */
    case PageFromCache;

    /** load from client cache */
    case PageFromClientCache;
}

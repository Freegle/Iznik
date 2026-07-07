/**
 * Comprehensive jscodeshift transformer to analyze all API calls
 * and track which stores/components use them
 *
 * Usage: jscodeshift -t analyze-all-api-calls.js --dry --silent <directory>
 */

module.exports = function(fileInfo, api) {
  const j = api.jscodeshift;
  const root = j(fileInfo.source);

  // Only process if this file has interesting content
  const hasStores = root.find(j.CallExpression, {
    callee: {
      name: name => name && name.startsWith('use') && name.endsWith('Store')
    }
  }).size() > 0;

  const hasApiCalls = root.find(j.CallExpression, {
    callee: {
      name: 'api'
    }
  }).size() > 0;

  if (!hasStores && !hasApiCalls) {
    return fileInfo.source;
  }

  const analysis = {
    file: fileInfo.path.replace(/.*\/(iznik-[^/]+)\//, '$1/'),
    stores: {},
    apiCalls: []
  };

  // Find all Pinia store usage patterns
  // Pattern 1: const store = useXStore()
  root.find(j.VariableDeclarator, {
    init: {
      type: 'CallExpression',
      callee: {
        name: name => name && name.startsWith('use') && name.endsWith('Store')
      }
    }
  }).forEach(path => {
    const storeName = path.node.init.callee.name;

    if (!analysis.stores[storeName]) {
      analysis.stores[storeName] = {
        variables: [],
        methods: [],
        destructured: []
      };
    }

    if (path.node.id.type === 'Identifier') {
      // const messageStore = useMessageStore()
      analysis.stores[storeName].variables.push(path.node.id.name);
    } else if (path.node.id.type === 'ObjectPattern') {
      // const { markSeen, fetch } = useMessageStore()
      path.node.id.properties.forEach(prop => {
        if (prop.key && prop.key.name) {
          analysis.stores[storeName].destructured.push(prop.key.name);
        }
      });
    }
  });

  // Pattern 2: Direct method calls useXStore().method()
  root.find(j.MemberExpression, {
    object: {
      type: 'CallExpression',
      callee: {
        name: name => name && name.startsWith('use') && name.endsWith('Store')
      }
    }
  }).forEach(path => {
    const storeName = path.node.object.callee.name;
    const methodName = path.node.property.name;

    if (!analysis.stores[storeName]) {
      analysis.stores[storeName] = {
        variables: [],
        methods: [],
        destructured: []
      };
    }

    if (!analysis.stores[storeName].methods.find(m => m.name === methodName && m.type === 'direct')) {
      analysis.stores[storeName].methods.push({
        name: methodName,
        type: 'direct'
      });
    }
  });

  // Find method calls on store variables
  Object.entries(analysis.stores).forEach(([storeName, info]) => {
    info.variables.forEach(varName => {
      root.find(j.MemberExpression, {
        object: { name: varName }
      }).forEach(path => {
        if (path.node.property && path.node.property.name) {
          const methodName = path.node.property.name;

          // Check if this is actually a method call (has parentheses after)
          const parent = path.parent.value;
          if (parent.type === 'CallExpression' && parent.callee === path.node) {
            if (!info.methods.find(m => m.name === methodName && m.variable === varName)) {
              info.methods.push({
                name: methodName,
                type: 'variable',
                variable: varName
              });
            }
          }
        }
      });
    });
  });

  // Find all API calls: api(config).object.method(params)
  root.find(j.CallExpression).forEach(path => {
    const node = path.node;

    // Check if this is a call on a member of api().something
    if (node.callee.type === 'MemberExpression') {
      const memberExpr = node.callee;

      if (memberExpr.object && memberExpr.object.type === 'MemberExpression') {
        const innerMember = memberExpr.object;

        if (innerMember.object &&
            innerMember.object.type === 'CallExpression' &&
            innerMember.object.callee.name === 'api') {

          const apiObject = innerMember.property.name;
          const apiMethod = memberExpr.property.name;
          const line = node.loc ? node.loc.start.line : null;

          // Get the actual endpoint if we can determine it
          let endpoint = `/${apiObject}`;

          // Special cases for known patterns
          if (apiMethod === 'fetchMessages') {
            endpoint = '/messages';
          } else if (apiMethod === 'markSeen') {
            endpoint = '/messages?action=MarkSeen';
          } else if (apiMethod === 'fetch' || apiMethod === 'fetchv2') {
            endpoint = `/${apiObject}/{id}`;
          } else if (apiMethod === 'list') {
            endpoint = `/${apiObject}s`;
          }

          analysis.apiCalls.push({
            object: apiObject,
            method: apiMethod,
            endpoint: endpoint,
            line: line
          });
        }
      }
    }
  });

  // Clean up empty structures
  Object.keys(analysis.stores).forEach(key => {
    const store = analysis.stores[key];
    if (store.variables.length === 0 &&
        store.methods.length === 0 &&
        store.destructured.length === 0) {
      delete analysis.stores[key];
    }
  });

  // Output results if we found anything interesting
  if (Object.keys(analysis.stores).length > 0 || analysis.apiCalls.length > 0) {
    console.log(JSON.stringify(analysis, null, 2));
  }

  return fileInfo.source;
};